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
use App\Service\ProductService;

class UpdateProductTranslations extends Command
{
    protected static $defaultName = 'app:send-translations';
    protected static $defaultDescription = 'Updated product translations';

    private $http_client;
    private $params;
    private $display_fields = ['line_no','sku'];
    private $sync_fields = ['title','description','short_description'];

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
                ->  addOption('locale', null, InputOption::VALUE_OPTIONAL, 'Locale')
        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io -> title('Translation import:');

        $file = $input -> getOption('file');
        $locale = $input -> getOption('locale');
        $key = $input -> getOption('key') ?? $this -> params -> get('pling_api_key');
        $server = $input -> getOption('server') ?? $this -> params -> get('pling_server');


        if($locale == null){

            $locale = $io -> ask('Please enter a locale code (eg de_DE)',null,function($locale){

                if(empty(trim($locale))){
                    throw new \RuntimeException('Invalid locale');
                }

                return $locale;

            });

        }

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

            $io -> error("Deine Daten enthalten ".count($invalid_lines)." fehlerhafte Zeilen.");

            $io -> table(array_keys($invalid_lines[0]),$invalid_lines);
            return Command::FAILURE;

        }

        $line_count = count($data);

        $io -> info("Bereit {$line_count} Updates zu senden.");
        $io -> info("Sende Daten an ".$server." mit dem Key ".$key);

        $io -> progressStart($line_count);

        $chunk_size = 100;
        $chunks = array_chunk($data,$chunk_size);
        $failed_lines = [];


        foreach($chunks as $chunk){


            foreach($chunk as $line){


                $io -> progressAdvance();



                $response = $this -> http_client -> request(
                    'POST',
                    $server.'/api/erp/v2/product/search', [
                        'headers' => [
                            'Content-Type' => 'text/json',
                            'Token' => $key
                        ],
                        'body' => json_encode([
                            'sku' => $line['sku'],
                            'page_size' => 1,
                            'product_type' => ['simple','variable'],
                            'expand' => ['description'],
                            'locale' => $locale
                        ]),
                    ]
                );


                $content = $response->toArray();

                $product = array_pop($content['payload']['results']);


                // Skip product if it does not exist.
                if(empty($product)){

                    $line = array_intersect_key($line, array_flip($this -> display_fields));
                    $line['sync_failed'] = 'failed';
                    $line['fail_reason'] = 'Product not found';
                    $failed_lines[] = $line;
                    continue;

                }

                // Check if product needs an update
                $changed_data = array_diff(array_intersect_key($product,array_flip($this->sync_fields)),$line);

                // Skip products which do not need an update
                if(empty($changed_data)){continue;}


                $payload =array_intersect_key($line,array_flip($this -> sync_fields));
                $payload['is_finished'] = true;

                $this -> http_client -> request(
                    'POST',
                    $server.'/api/erp/v2/translator/product/'.$product['id'].'/'.$locale, [
                        'headers' => [
                            'Content-Type' => 'text/json',
                            'Token' => $key
                        ],
                        'body' => json_encode($payload),
                    ]
                );

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
                $nl[strtolower($header)] = $line[$key];
            }


            $nl = $this -> validate_line($nl);

            $parsed[] = $nl;

        }

        return $parsed;

    }


    private function validate_line($line){


        $required_fields = ['sku'];
        $supported_fields = ['line_no','sku','title','description','short_description'];
        $line = array_intersect_key($line, array_flip($supported_fields));

        if(count($line) === 1){


            $line['line_is_valid'] = 'no';
            $line['line_validation_message'] = 'No valid fields found in this line.';
            return $line;

        }


        $line['line_is_valid'] = 'yes';
        $line['line_validation_message'] = '';



        foreach($required_fields as $field){

            if( !array_key_exists($field,$line) || $line[$field] == ''){

                $line['line_is_valid'] = 'no';
                $line['line_validation_message'] = 'Field '.$field.' does not exist or is empty.';
                return $line;

            }

        }

        return $line;

    }

}
