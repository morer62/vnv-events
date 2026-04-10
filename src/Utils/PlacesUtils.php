<?php

namespace App\Utils;

use Exception;

class PlacesUtils
{
    /**
     * @throws Exception
     */
    public static function getCoordinates($address): array
    {
        $apiKey = $_ENV["GOOGLE_KEY"];
        $address = urlencode($address);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";

        // Send request
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        // Check if the response is valid
        if ($data['status'] == 'OK') {
            $latitude = $data['results'][0]['geometry']['location']['lat'];
            $longitude = $data['results'][0]['geometry']['location']['lng'];
            return [$latitude, $longitude];
        } else {
            throw new Exception("Could not get coordinates");
        }
    }
}