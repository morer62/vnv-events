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
        $county=(string)($parts['administrative_area_level_2']??''); $city=(string)($parts['locality']??$parts['postal_town']??$parts['sublocality']??''); $state=(string)($parts['administrative_area_level_1']??''); $zip=substr((string)($parts['postal_code']??''),0,5);
        $settings=(new DeliveryPricingService())->settings($ownerId,$siteKey); $area=json_decode((string)($settings['service_area_json']??'{}'),true)?:[];
        $allowedCounties=array_map('strtolower',(array)($area['counties']??[])); $allowedCities=array_map('strtolower',(array)($area['cities']??[]));
        $countyKey=strtolower($county);$cityKey=strtolower($city);$isCoreCounty=in_array($countyKey,['miami-dade county','broward county'],true);$isConfiguredCity=in_array($cityKey,$allowedCities,true);
        $southPalmBeachZips=[];$this->loadZoneZips($ownerId,$siteKey,'Palm Beach County',$southPalmBeachZips);$isSouthPalmBeach=$countyKey==='palm beach county'&&$zip!==''&&in_array($zip,$southPalmBeachZips,true);
        if(!in_array(strtolower($state),['florida','fl'],true)||(!$isCoreCounty&&!$isSouthPalmBeach&&!$isConfiguredCity)) throw new \DomainException('This address is outside our delivery area. We currently serve all of Miami-Dade and Broward, plus Boca Raton, Delray Beach and Boynton Beach in south Palm Beach County.');
        return ['formatted_address'=>(string)($result['formatted_address']??$address),'county'=>$county,'city'=>$city,'state'=>$state,'zip'=>$zip,'latitude'=>(float)($result['geometry']['location']['lat']??0),'longitude'=>(float)($result['geometry']['location']['lng']??0)];
    }

    /** @param string[] $target */
    private function loadZoneZips(int $ownerId,string $siteKey,string $county,array &$target): void
    {
        try{$db=new \App\Repositories\Connection();$db->query("SELECT zip_codes_json FROM store_gourmet_delivery_zones WHERE id_owner=:owner AND site_key=:site AND county_name=:county AND status='ACTIVE' LIMIT 1");$db->bind(':owner',$ownerId,\PDO::PARAM_INT);$db->bind(':site',$siteKey);$db->bind(':county',$county);$row=$db->fetchOne();$target=array_values(array_filter(array_map('strval',(array)json_decode((string)($row->zip_codes_json??'[]'),true))));}catch(\Throwable $e){$target=[];}
    }
}
