<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UpdatePackageContentsCommand extends Command
{
    protected static $defaultName = 'app:update-package-contents';
    protected static $defaultDescription = 'Update package contents';

    private $http_client;
    private $params;

    public function __construct(HttpClientInterface $http_client, ParameterBagInterface $params){

        $this -> http_client = $http_client;
        $this -> params = $params;

        parent::__construct();

    }

    protected function configure(): void
    {

        $this   ->  addOption('key', null, InputOption::VALUE_OPTIONAL, 'API Key')
            ->  addOption('server', null, InputOption::VALUE_OPTIONAL, 'API Key')
            ->  addOption('file', null, InputOption::VALUE_OPTIONAL, 'Abspath to XLS file')
        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io -> title('Preparing to send package contents:');

        $file = $input -> getOption('file');
        $key = $input -> getOption('key') ?? $this -> params -> get('pling_api_key');
        $server = $input -> getOption('server') ?? $this -> params -> get('pling_server');

        if($file == null || !file_exists($file)){
            $file = $io -> ask('Bitte Pfad zur XLS-Datei angeben.',null,function($file){

                if(!file_exists($file)){
                    throw new \RuntimeException('Der angegebene Pfad ist ungültig.');
                }

                return $file;

            });
        }

        if($server == null || !filter_var($server, FILTER_VALIDATE_URL)){
            $server = $io -> ask('Bitte gib einen Zielserver an.',null, function($url){

                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException('Bitte gibt eine gültige URL für den Server an!');
                }

                return $url;

            });
        }

        if($key == null || $key == ''){

            $key = $io -> ask('Bitte gib deinen API-Schlüssel ein.',null,function ($key) {
                if (empty($key)) {throw new \RuntimeException('Du musst einen API-Schlüssel eingeben!');}
                return $key;
            });

        }

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $document = $reader->load($file);

        $sheet_index = $io -> choice('Bitte wähle ein Tabellenblatt zum Verarbeiten aus.',$document->getSheetNames(),0);

        $io -> writeln('Beginne Verarbeitung');

        $sheet = $document -> getSheetByName($sheet_index);
        $data = $this -> parse_xls_sheet($sheet);

        $invalid_lines = array_filter($data,function($line){
            return $line['line_is_valid'] !== 'yes';
        });

        if(count($invalid_lines) > 0){

            $io -> warning("Deine Daten enthalten ".count($invalid_lines)." fehlerhafte Zeilen.");

            $io -> table(array_keys($invalid_lines[0]),$invalid_lines);
            return Command::FAILURE;

        }

        $line_count = count($data);
        $io -> info("Bereit {$line_count} Paketinhalte zu senden.");

        $payloads = $this -> convert_payload($data);
        $payload_count = count($payloads);

        $io -> info("Es werden insgesamt ".$payload_count.' Requests benötigt.');

        $io -> info("Sende Daten an ".$server." mit dem Key ".$key);
        $io -> progressStart($payload_count);


        $failed_lines = [];



        foreach($payloads as $parent => $payload){


            $io -> progressAdvance();


            // Get Product by SKU


            $line = [
                'sku' => $payload['sku'],
                'contents' => implode(',',array_column($payload['picklist'],'sku')),
                'errors' => ''
            ];

            $response = $this -> http_client -> request(
                'POST',
                $server.'/api/v3/product/view', [
                    'headers' => [
                        'Content-Type' => 'text/json',
                        'Token' => $key
                    ],
                    'body' => json_encode(['sku' => $payload['sku']]),
                ]
            );

            if($response -> getStatusCode() !== 200){
                $line['error'] = 'Master not found.';
                $failed_lines[] = $line;
                continue;
            }

            $response_payload = $response->toArray();


            if(!isset($response_payload['payload']['id'])){

                $line['error'] = 'Master not found.';
                $failed_lines[] = $line;
                continue;
            }



            unset($payload['sku']);
            $payload['id'] = $response_payload['payload']['id'];


            $response = $this -> http_client -> request(
                'PATCH',
                $server.'/api/v3/product', [
                    'headers' => [
                        'Content-Type' => 'text/json',
                        'Token' => $key
                    ],
                    'body' => json_encode($payload),
                ]
            );


           if($response -> getStatusCode() !== 200){

               $line['error'] = 'Response code '.$response->getStatusCode();
               $failed_lines[] = $line;

           } else {

               $response_data = $response -> toArray(false);

               if(!empty($response_data['all_errors'])){

                   $line['error'] = implode("\r\n",$response_data['all_errors']);

               }

           }

        }

        $io -> progressFinish();

        if(count($failed_lines) > 0){

            $io -> table(array_keys($failed_lines[0]),$failed_lines);
            $io -> error("Einige Updates waren nicht erfolgreich.");
            return Command::FAILURE;

        }

        $io -> success("All updates finished successful");
        return Command::SUCCESS;

    }



    private function parse_xls_sheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet){

        $data = $sheet -> toArray();
        $headers = array_filter($data[0]);
        unset($data[0]);

        $i=2;

        foreach($data as $line){

            if(empty(array_filter($line))){continue;}

            $nl = ['line_no' => $i++];

            foreach($headers as $key => $header){
                $nl[$header] = $line[$key];
            }


            $nl = $this -> validate_line($nl);

            $parsed[] = $nl;

        }



        return $parsed;

    }


    private function convert_payload($parsed){

        $data = [];

        foreach($parsed as $item){

            if(!array_key_exists($item['parent_sku'],$data)){

                $data[$item['parent_sku']] = [
                    'sku' => $item['parent_sku'],
                    'picklist' => []
                ];

            }

            $item['sku'] = $item['content_sku'];
            $item['quantity'] = $item['content_quantity'];


            $data[$item['parent_sku']]['picklist'][] = array_intersect_key($item,array_flip(['sku','quantity']));

        }



        return $data;

    }


    private function validate_line($line){



        $line['line_is_valid'] = 'yes';
        $line['line_validation_message'] = '';


        foreach($line as &$cell){
            if($cell == 'NULL'){$cell = null;}
        }


        if(empty($line['content_sku'])){

            $line['line_is_valid'] = 'no';
            $line['line_validation_message'] = 'Content SKU is missing';

        }

        if(empty($line['parent_sku'])){

            $line['line_is_valid'] = 'no';
            $line['line_validation_message'] = 'Parent SKU is missing';

        }

        if(empty($line['content_quantity'])){

            $line['line_is_valid'] = 'no';
            $line['line_validation_message'] = 'Quantity is missing';

        }

        return $line;

    }

}
