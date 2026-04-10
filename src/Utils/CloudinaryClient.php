<?php

namespace App\Utils;

use Cloudinary\Cloudinary;

class CloudinaryClient
{
    public static function client(): Cloudinary
    {
        return new Cloudinary([
            'cloud' => [
                'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'],
                'api_key'    => $_ENV['CLOUDINARY_API_KEY'],
                'api_secret' => $_ENV['CLOUDINARY_API_SECRET'],
            ],
        ]);
    }
}
