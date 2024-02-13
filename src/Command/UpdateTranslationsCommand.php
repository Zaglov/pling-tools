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

class UpdateTranslationsCommand extends Command
{
    protected static $defaultName = 'app:import-translations';
    protected static $defaultDescription = 'Import Translations';

    private $http_client;
    private $params;
    private $locale;

    private $server;
    private $key;

    private $fields = ['id','object_type','field'];
    private $object_types = ['attribute','category','attribute_value'];

    public function __construct(HttpClientInterface $http_client, ParameterBagInterface $params){

        $this -> http_client = $http_client;
        $this -> params = $params;

        parent::__construct();

    }

    protected function configure(): void
    {

        $this -> addOption('file', null, InputOption::VALUE_OPTIONAL, 'Abspath to XLS file')
              -> addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale to import');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io -> title('Preparing to send package contents:');

        $file = $input -> getOption('file');
        $locale = $input -> getOption('locale');
        $key = $this -> params -> get('pling_api_key');
        $server = $this -> params -> get('pling_server');

        $this -> locale = $locale;

        if(!$locale){

            $io -> error("Please provide locale");
            return Command::FAILURE;

        }



        if(!$server || !filter_var($server, FILTER_VALIDATE_URL)){

            $io -> error("Invalid Server");
            return Command::FAILURE;

        }


        $this -> server = $server;


        if($key == null || $key == ''){

            $io -> error("API Key Missing");
            return Command::FAILURE;

        }

        $this -> key = $key;

        if($file == null || !file_exists($file)){
            $file = $io -> ask('Bitte Pfad zur XLS-Datei angeben.',null,function($file){

                if(!file_exists($file)){
                    throw new \RuntimeException('Der angegebene Pfad ist ungültig.');
                }

                return $file;

            });
        }


        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $document = $reader->load($file);

        $sheet_index = $io -> choice('Bitte wähle ein Tabellenblatt zum Verarbeiten aus.',$document->getSheetNames(),0);

        $io -> writeln('Beginne Verarbeitung');




        $sheet = $document -> getSheetByName($sheet_index);
        $data = $this -> parse_xls_sheet($sheet);

        foreach($data as &$translation){


            $translation['sent'] = 'no';
            $translation['errors'] = 'Line is invalid';

            if(!$translation['line_is_valid']){continue;}

            $translation['errors'] = [];
            $translation = $this -> sendUpdate($translation);

            $translation['errors'] = implode("\r\n",$translation['errors']);


        }

        $io -> table(array_keys($data[0]),$data);





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

    private function validate_line($line){


        $fields = $this -> fields;
        $fields[] = $this->locale;


        $line = array_intersect_key($line,array_flip($fields));


        $line['line_is_valid'] = 'yes';
        $line['line_validation_message'] = [];


        if(count(array_intersect_key($line,array_flip($fields))) < count($this -> fields)){

            $line['line_is_valid'] = 'no';
            $line['line_validation_message'][] = 'Insufficcient data';

        } else {

            if(!array_key_exists($this->locale,$line) || empty($line[$this->locale])){

                $line['line_is_valid'] = 'no';
                $line['line_validation_message'][] = 'Translation in '.$this->locale.' is missing.';

            }

            if(!in_array($line['object_type'],$this->object_types)){

                $line['line_is_valid'] = 'no';
                $line['line_validation_message'][] = 'Object type '.$line['object_type'].' is not supported.';

            }


        }


        $line['line_validation_message'] = implode("\r\n",$line['line_validation_message']);

        return $line;

    }


    public function sendUpdate($line) : array{


        $method = null;
        $endpoint = null;
        $payload = null;

        switch($line['object_type']){


            case 'attribute':

                $method = 'POST';
                $endpoint = "/api/erp/v2/attributes/".$line['id']."/update";

                $payload = [

                    $line['field'] => $line[$this -> locale],
                    'locale' => $this -> locale

                ];

                break;


            case 'category':

                $method = 'PATCH';
                $endpoint = '/api/v3/product-categories/'.$line['id'];
                $payload = [

                    $line['field'] => $line[$this -> locale],
                    'locale' => $this -> locale

                ];

                break;


            case 'attribute_value':

                $method = 'POST';
                $endpoint = '/api/erp/v2/attributes/option/'.$line['id'].'/update';
                $payload = [

                    $line['field'] => $line[$this -> locale],
                    'locale' => $this -> locale

                ];

                break;

        }

        if(!$method || !$endpoint || !$payload){

            $line['errors'][] = 'Could not generate valid payload.';
            return $line;

        }


        $response = $this -> http_client -> request(
            $method,
            $this -> server.$endpoint, [
                'headers' => [
                    'Content-Type' => 'text/json',
                    'Token' => $this -> key
                ],
                'body' => json_encode($payload),
            ]
        );


        if($response -> getStatusCode() !== 200){

            $line['errors'][] = 'Request failed: '.$response -> getStatusCode();

        }


        $response_payload = json_decode($response -> getContent(false));



        if(is_array($response_payload) && !empty($resplonse_payload['all_errors'])){


            foreach($resplonse_payload['all_errors'] as $error){

                $line['errors'][] = $error;

            }

        } else {


            $line['sent'] = 'yes';

        }


        return $line;

    }

}
