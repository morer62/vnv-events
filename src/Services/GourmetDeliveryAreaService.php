<?php
namespace App\Services;
use App\Services\Delivery\DeliveryPricingService;
final class GourmetDeliveryAreaService
{
    public function validate(int $ownerId,string $siteKey,string $address): array
    {
        $address=trim($address); if($address==='') throw new \InvalidArgumentException('A complete delivery address is required.');
        $apiKey=trim((string)($_ENV['GOOGLE_MAPS_API_KEY']??$_ENV['GOOGLE_KEY']??'')); if($apiKey==='') throw new \RuntimeException('Google address validation is not configured.');
        $url='https://maps.googleapis.com/maps/api/geocode/json?address='.rawurlencode($address).'&key='.rawurlencode($apiKey);
        $raw=@file_get_contents($url,false,stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true]])); $data=$raw!==false?json_decode($raw,true):null;
        if(!is_array($data)||($data['status']??'')!=='OK'||empty($data['results'][0])) throw new \DomainException('We could not verify this delivery address. Please enter a complete address.');
        $result=$data['results'][0]; $parts=[]; foreach(($result['address_components']??[]) as $component){foreach(($component['types']??[]) as $type)$parts[$type]=$component['long_name']??'';}
        $county=(string)($parts['administrative_area_level_2']??''); $city=(string)($parts['locality']??$parts['postal_town']??$parts['sublocality']??''); $state=(string)($parts['administrative_area_level_1']??'');
        $settings=(new DeliveryPricingService())->settings($ownerId,$siteKey); $area=json_decode((string)($settings['service_area_json']??'{}'),true)?:[];
        $allowedCounties=array_map('strtolower',(array)($area['counties']??[])); $allowedCities=array_map('strtolower',(array)($area['cities']??[]));
        if(!in_array(strtolower($state),['florida','fl'],true)||(!in_array(strtolower($county),$allowedCounties,true)&&!in_array(strtolower($city),$allowedCities,true))) throw new \DomainException('This address is outside our current delivery area: Miami-Dade County, Broward County and Boca Raton.');
        return ['formatted_address'=>(string)($result['formatted_address']??$address),'county'=>$county,'city'=>$city,'state'=>$state,'latitude'=>(float)($result['geometry']['location']['lat']??0),'longitude'=>(float)($result['geometry']['location']['lng']??0)];
    }
}
