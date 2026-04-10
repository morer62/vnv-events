<?php

namespace App\Utils;

use Aws\S3\S3Client;
 
class WasabiClient
{
    public static function client(): S3Client
    {
        
        return new S3Client([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'endpoint'    => 'https://s3.us-east-1.wasabisys.com',
            'credentials' => [
                'key'    => $_ENV['WASABI_KEY'],
                'secret' => $_ENV['WASABI_SECRET'],
            ],
        ]); 
    }
}
