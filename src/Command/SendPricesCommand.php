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

class SendPricesCommand extends Command
{
    protected static $defaultName = 'app:send-prices';
    protected static $defaultDescription = 'Add a short description for your command';

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
        $io -> title('Preparing to import prices:');

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

        $io -> info("Bereit {$line_count} Preis-Updates zu senden.");

        $io -> info("Sende Daten an ".$server." mit dem Key ".$key);
        $io -> progressStart($line_count);


        $chunk_size = 100;
        $chunks = array_chunk($data,$chunk_size);
        $failed_lines = [];

        foreach($chunks as $chunk){

            $response = $this -> http_client -> request(
                'POST',
                $server.'/api/erp/v2/product/prices', [
                    'headers' => [
                        'Content-Type' => 'text/json',
                        'Token' => $key
                    ],
                    'body' => json_encode($chunk),
                ]
            );



            $io -> progressAdvance(count($chunk));

            $content = $response->toArray();



            foreach($content['payload'] as $index => $result){


                if($result == false){

                    $line = $chunk[$index];
                    $line['sync_failed'] = 'failed';
                    $failed_lines[] = $line;

                }

            }



        }


        $io -> progressFinish();




        if(count($failed_lines) > 0){

            $io -> table(array_keys($failed_lines[0]),$failed_lines);
            $io -> error("Einige Updates waren nicht erfolgreich.");
            return Command::FAILURE;

        }



        return Command::SUCCESS;
    }



    private function parse_xls_sheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet){

        $data = $sheet -> toArray();
        $parsed = [];


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


    private function validate_line($line){

        $line['line_is_valid'] = 'yes';
        $line['line_validation_message'] = '';

        if(array_key_exists('min_quantity',$line)){

            if(!is_numeric($line['min_quantity'])){


                $line['line_is_valid'] = 'no';
                $line['line_validation_message'] = 'Min quantity is not numeric.';
                return $line;

            }


            $line['min_quantity'] = intval($line['min_quantity']);

        }
        else {$line['min_quantity'] = 1;}

        $required_fields = ['sku','regular_price','price_list'];

        foreach($required_fields as $field){

            if( !array_key_exists($field,$line) || $line[$field] == ''){

                $line['line_is_valid'] = 'no';
                $line['line_validation_message'] = 'Field '.$field.' does not exist or is empty.';
                return $line;

            }

        }

        if($line['regular_price'] <= 0 && $line['regular_price'] !== null){

            $line['line_is_valid'] = 'no';
            $line['line_validation_message'] = 'Regular price is 0 or lower.';
            return $line;

        }

        if(array_key_exists('sale_price',$line)){

            if($line['regular_price'] <= $line['sale_price']){

                $line['line_is_valid'] = 'no';
                $line['line_validation_message'] = 'Sale price is higher or equals regular price.';
                return $line;

            }

            if($line['sale_price'] <= 0 && $line['sale_price'] !== null && $line['sale_price'] != ''){

                $line['line_is_valid'] = 'no';
                $line['line_validation_message'] = 'Sale price is 0';
                return $line;

            }

        }

        return $line;

    }

}
