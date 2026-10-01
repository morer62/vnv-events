<?php

namespace App\Services\Delivery;

use App\Repositories\Connection;

final class DeliveryPricingService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    public function settings(int $ownerId, string $siteKey): array
    {
        $this->db->query('SELECT * FROM store_gourmet_settings WHERE id_owner=:owner AND site_key=:site LIMIT 1');
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':site', $siteKey);
        $row = $this->db->fetchOne();
        return $row ? (array)$row : [];
    }

    public function priceQuote(array $providerQuote, int $ownerId, string $siteKey): array
    {
        $settings = $this->settings($ownerId, $siteKey);
        $providerCost = max(0.0, (float)($providerQuote['provider_cost'] ?? 0));
        $distance = isset($providerQuote['distance_miles']) ? (float)$providerQuote['distance_miles'] : null;

        $historicalPeak = max(0.0, (float)($providerQuote['historical_peak_cost'] ?? 0));
        $referenceCost = max($providerCost, $historicalPeak);
        if ($referenceCost > 0) {
            $markup = max(0.0, (float)($settings['delivery_markup_percent'] ?? 10));
            $increment = max(0.0, (float)($settings['delivery_rounding_increment'] ?? 1));
            $rawFee = $referenceCost * (1 + $markup / 100);
            $customerFee = $increment > 0 ? ceil($rawFee / $increment) * $increment : $rawFee;
        } else {
            $customerFee = $this->fallbackFee($distance, $ownerId, $siteKey, $settings['fallback_delivery_tiers_json'] ?? '[]');
        }
        $minimum = max(0.0, (float)($settings['delivery_minimum_fee'] ?? 0));
        $customerFee = max($minimum, $customerFee);

        return [
            'provider_cost' => round($providerCost, 2),
            'historical_peak_cost' => round($historicalPeak, 2),
            'customer_fee' => round($customerFee, 2),
            'delivery_margin' => round($customerFee - $providerCost, 2),
            'currency' => strtoupper((string)($providerQuote['currency'] ?? 'USD')),
            'distance_miles' => $distance,
            'markup_percent' => (float)($settings['delivery_markup_percent'] ?? 10),
            'rounding_increment' => (float)($settings['delivery_rounding_increment'] ?? 1),
            'minimum_fee' => $minimum,
            'pricing_source' => $historicalPeak > $providerCost ? 'HISTORICAL_PEAK' : ($providerCost > 0 ? 'UBER_LIVE' : 'DISTANCE_FALLBACK'),
        ];
    }

    /** Validate, quote, price and persist one immutable delivery reference. */
    public function quoteDelivery(int $ownerId, string $siteKey, array $destination, ?string $requestedAt = null, string $context = 'CHECKOUT', ?int $orderId = null, ?float $subtotal = null): array
    {
        $settings = $this->settings($ownerId, $siteKey);
        if (!$settings) throw new \RuntimeException('Delivery settings are not configured.');
        $pickup = [
            'name'=>(string)($settings['pickup_name']??'VNV Sunrise'),'address_1'=>(string)($settings['pickup_address_1']??''),
            'city'=>(string)($settings['pickup_city']??''),'state'=>(string)($settings['pickup_state']??''),'zip'=>(string)($settings['pickup_zip']??''),
            'country'=>(string)($settings['pickup_country']??'US'),'latitude'=>(float)($settings['pickup_latitude']??0),'longitude'=>(float)($settings['pickup_longitude']??0),
        ];
        foreach (['address_1','city','state','zip'] as $required) if (trim((string)($destination[$required]??''))==='') throw new \InvalidArgumentException('Enter a complete delivery address before calculating delivery.');
        $validated = (new \App\Services\GourmetDeliveryAreaService())->validate($ownerId, $siteKey, implode(', ', array_filter([$destination['address_1'],$destination['address_2']??'', $destination['city'],trim($destination['state'].' '.$destination['zip'])])));
        $destination = array_merge($destination, ['formatted_address'=>$validated['formatted_address'],'latitude'=>$validated['latitude'],'longitude'=>$validated['longitude'],'zip'=>$destination['zip']]);
        $distance = $this->haversine((float)$pickup['latitude'],(float)$pickup['longitude'],(float)$destination['latitude'],(float)$destination['longitude']);
        $maximum = max(0.1,(float)($settings['delivery_max_distance_miles']??30));
        if ($distance > $maximum) throw new \DomainException('This address is currently outside our delivery area. Please contact VNV for assistance.');

        $cached = $this->cachedQuote($ownerId,$siteKey,$destination,$requestedAt,(int)($settings['delivery_quote_cache_minutes']??5));
        if ($cached) {
            $zone=(new \App\Services\GourmetExpressService($this->db))->deliveryZone($ownerId,$siteKey,(string)$destination['zip'],max(0,(float)($subtotal??0)));
            $cached['customer_fee']=round((float)$zone['fee'],2);
            $cached['delivery_margin']=round((float)$cached['customer_fee']-(float)$cached['provider_cost'],2);
            $cached['pricing_source']='FIXED_ZONE';$cached['zone_name']=$zone['name'];$cached['free_delivery_threshold']=$zone['free_threshold'];
            return $cached + ['cached'=>true];
        }
        $historicalPeak = $this->historicalPeak($ownerId,$siteKey,$destination,$requestedAt,$settings);
        $provider = new UberQuoteReferenceProvider();
        $live = $provider->getQuote($pickup,$destination,$this->deliveryWindow($requestedAt));
        $live['distance_miles']=$distance; $live['historical_peak_cost']=$historicalPeak;
        $priced = $this->priceQuote($live,$ownerId,$siteKey);
        // Uber remains an internal cost reference. The customer-facing fee is
        // the fixed VNV county-zone fee (or free above its configured threshold).
        $zone = (new \App\Services\GourmetExpressService($this->db))->deliveryZone($ownerId,$siteKey,(string)$destination['zip'],max(0,(float)($subtotal??0)));
        $priced['customer_fee'] = round((float)$zone['fee'],2);
        $priced['delivery_margin'] = round((float)$priced['customer_fee']-(float)$priced['provider_cost'],2);
        $priced['pricing_source'] = 'FIXED_ZONE';
        $priced['zone_name'] = $zone['name'];
        $priced['free_delivery_threshold'] = $zone['free_threshold'];
        $result = array_merge($live,$priced,[
            'pickup'=>$pickup,'destination'=>$destination,'requested_delivery_at'=>$requestedAt,
            'quote_context'=>strtoupper($context),'queried_at'=>gmdate('Y-m-d H:i:s'),'cached'=>false,
        ]);
        $result['quote_id']=$this->saveQuote($ownerId,$siteKey,$result,$orderId);
        return $result;
    }

    public function recentZones(int $ownerId,string $siteKey): array
    {
        $this->db->query("SELECT z.*,q.distance_miles,q.provider_cost,q.historical_peak_cost,q.customer_fee,q.pricing_source,q.queried_at FROM store_delivery_reference_zones z LEFT JOIN store_delivery_quotes q ON q.id=(SELECT q2.id FROM store_delivery_quotes q2 WHERE q2.id_owner=z.id_owner AND q2.site_key=z.site_key AND q2.destination_zip=z.zip_code ORDER BY q2.queried_at DESC LIMIT 1) WHERE z.id_owner=:owner AND z.site_key=:site AND z.status='ACTIVE' ORDER BY z.sort_order,z.zone_name");
        $this->db->bind(':owner',$ownerId); $this->db->bind(':site',$siteKey); return array_map(fn($row)=>(array)$row,$this->db->fetchAll());
    }

    private function cachedQuote(int $ownerId,string $siteKey,array $destination,?string $requestedAt,int $minutes): ?array
    {
        if($minutes<=0)return null;
        $this->db->query("SELECT * FROM store_delivery_quotes WHERE id_owner=:owner AND site_key=:site AND destination_address=:address AND status='QUOTED' AND queried_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL :minutes MINUTE) AND ((:requested IS NULL AND requested_delivery_at IS NULL) OR requested_delivery_at=:requested2) ORDER BY id DESC LIMIT 1");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':address',(string)$destination['formatted_address']);$this->db->bind(':minutes',$minutes,\PDO::PARAM_INT);$this->db->bind(':requested',$requestedAt);$this->db->bind(':requested2',$requestedAt);
        $row=$this->db->fetchOne(); if(!$row)return null;
        return ['quote_id'=>(int)$row->id,'provider'=>(string)$row->provider,'provider_quote_id'=>$row->provider_quote_id,'provider_cost'=>(float)$row->provider_cost,'historical_peak_cost'=>(float)$row->historical_peak_cost,'customer_fee'=>(float)$row->customer_fee,'delivery_margin'=>(float)$row->delivery_margin,'currency'=>(string)$row->currency,'distance_miles'=>(float)$row->distance_miles,'eta_minutes'=>$row->eta_minutes!==null?(int)$row->eta_minutes:null,'expires_at'=>$row->expires_at,'pricing_source'=>(string)$row->pricing_source,'markup_percent'=>(float)$row->markup_percent,'rounding_increment'=>(float)$row->rounding_increment,'minimum_fee'=>(float)$row->minimum_fee,'pickup'=>['name'=>$row->pickup_name,'address_1'=>$row->pickup_address],'destination'=>['formatted_address'=>$row->destination_address,'zip'=>$row->destination_zip],'queried_at'=>$row->queried_at];
    }

    private function historicalPeak(int $ownerId,string $siteKey,array $destination,?string $requestedAt,array $settings): float
    {
        $when=$requestedAt?:gmdate('Y-m-d H:i:s');$date=new \DateTimeImmutable($when);$hour=(int)$date->format('G');$minute=(int)$date->format('i');$weekday=(int)$date->format('N');$from=max(0,$hour-1);$to=min(23,$hour+1);$weekdays=range(1,7);
        $windows=json_decode((string)($settings['delivery_peak_windows_json']??'[]'),true);foreach(is_array($windows)?$windows:[] as $window){$days=array_map('intval',(array)($window['weekdays']??[]));[$sh,$sm]=array_pad(array_map('intval',explode(':',(string)($window['start']??'00:00'))),2,0);[$eh,$em]=array_pad(array_map('intval',explode(':',(string)($window['end']??'23:59'))),2,0);$now=$hour*60+$minute;if(in_array($weekday,$days,true)&&$now>=$sh*60+$sm&&$now<=$eh*60+$em){$from=$sh;$to=$eh;$weekdays=$days;break;}}
        $mysqlDays=implode(',',array_map(fn($day)=>(($day%7)+1),$weekdays));
        $this->db->query("SELECT MAX(provider_cost) peak FROM store_delivery_quotes WHERE id_owner=:owner AND site_key=:site AND destination_zip=:zip AND provider_cost>0 AND HOUR(COALESCE(requested_delivery_at,queried_at)) BETWEEN :from_hour AND :to_hour AND DAYOFWEEK(COALESCE(requested_delivery_at,queried_at)) IN ($mysqlDays) AND queried_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$this->db->bind(':zip',(string)($destination['zip']??''));$this->db->bind(':from_hour',max(0,$hour-1),\PDO::PARAM_INT);$this->db->bind(':to_hour',min(23,$hour+1),\PDO::PARAM_INT);
        return (float)($this->db->fetchOne()->peak??0);
    }

    private function saveQuote(int $ownerId,string $siteKey,array $q,?int $orderId): int
    {
        $sql="INSERT INTO store_delivery_quotes (id_owner,site_key,id_store_order,provider,provider_quote_id,provider_reference,pickup_name,pickup_address,pickup_latitude,pickup_longitude,destination_address,destination_zip,destination_latitude,destination_longitude,requested_delivery_at,provider_cost,customer_fee,delivery_margin,currency,distance_miles,eta_minutes,pricing_source,historical_peak_cost,markup_percent,rounding_increment,minimum_fee,quote_context,queried_at,expires_at,status,raw_response) VALUES (:owner,:site,:order_id,:provider,:quote_id,:reference,:pickup_name,:pickup_address,:pickup_lat,:pickup_lng,:destination,:zip,:dest_lat,:dest_lng,:requested,:provider_cost,:customer_fee,:margin,:currency,:distance,:eta,:source,:peak,:markup,:rounding,:minimum,:context,:queried,:expires,'QUOTED',:raw)";
        $this->db->query($sql); $values=[':owner'=>$ownerId,':site'=>$siteKey,':order_id'=>$orderId,':provider'=>$q['provider']??'distance_fallback',':quote_id'=>$q['provider_quote_id']??null,':reference'=>null,':pickup_name'=>$q['pickup']['name']??null,':pickup_address'=>implode(', ',array_filter([$q['pickup']['address_1']??'',$q['pickup']['city']??'',$q['pickup']['state']??'',$q['pickup']['zip']??''])),':pickup_lat'=>$q['pickup']['latitude']??null,':pickup_lng'=>$q['pickup']['longitude']??null,':destination'=>$q['destination']['formatted_address']??null,':zip'=>$q['destination']['zip']??null,':dest_lat'=>$q['destination']['latitude']??null,':dest_lng'=>$q['destination']['longitude']??null,':requested'=>$q['requested_delivery_at']??null,':provider_cost'=>$q['provider_cost'],':customer_fee'=>$q['customer_fee'],':margin'=>$q['delivery_margin'],':currency'=>$q['currency'],':distance'=>$q['distance_miles'],':eta'=>$q['eta_minutes']??null,':source'=>$q['pricing_source'],':peak'=>$q['historical_peak_cost'],':markup'=>$q['markup_percent'],':rounding'=>$q['rounding_increment'],':minimum'=>$q['minimum_fee'],':context'=>$q['quote_context'],':queried'=>$q['queried_at'],':expires'=>$q['expires_at']??null,':raw'=>$q['raw_response']??null]; foreach($values as $key=>$value)$this->db->bind($key,$value);$this->db->execute();return (int)$this->db->lastId();
    }

    private function deliveryWindow(?string $requestedAt): array
    {
        if(!$requestedAt)return[];try{$ready=new \DateTimeImmutable($requestedAt);$deadline=$ready->modify('+60 minutes');return['dropoff_ready_dt'=>$ready->format(DATE_ATOM),'dropoff_deadline_dt'=>$deadline->format(DATE_ATOM)];}catch(\Throwable){return[];}
    }

    private function haversine(float $lat1,float $lon1,float $lat2,float $lon2): float
    {
        $earth=3958.7613;$dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);$a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;return round($earth*2*atan2(sqrt($a),sqrt(1-$a)),2);
    }

    private function fallbackFee(?float $distance, int $ownerId, string $siteKey, string $json): float
    {
        if ($distance === null || $distance < 0) {
            throw new \InvalidArgumentException('A delivery distance is required when live quoting is unavailable.');
        }
        $this->db->query("SELECT min_distance_miles min_miles,max_distance_miles max_miles,customer_fee fee FROM store_delivery_fallback_tiers WHERE id_owner=:owner AND site_key=:site AND status='ACTIVE' ORDER BY min_distance_miles");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',$siteKey);$rows=$this->db->fetchAll();
        $tiers=$rows?array_map(fn($row)=>(array)$row,$rows):json_decode($json, true);
        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            if ($distance >= (float)$tier['min_miles'] && $distance <= (float)$tier['max_miles']) {
                return (float)$tier['fee'];
            }
        }
        throw new \DomainException('The destination is outside the configured delivery range.');
    }
}
