<?php

namespace App\Services;

use App\Repositories\Connection;

final class GourmetScheduleService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    /** @return array{local:string,utc:string,timezone:string} */
    public function validateLocalDelivery(int $ownerId, string $siteKey, string $localInput): array
    {
        $this->db->query('SELECT * FROM store_gourmet_settings WHERE id_owner=:owner AND site_key=:site LIMIT 1');
        $this->db->bind(':owner',$ownerId,\PDO::PARAM_INT); $this->db->bind(':site',$siteKey);
        $settings=$this->db->fetchOne();
        if(!$settings) throw new \RuntimeException('Gourmet delivery settings are not configured.');
        $timezone=new \DateTimeZone((string)$settings->timezone);
        $local=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$localInput,$timezone);
        if(!$local) throw new \InvalidArgumentException('Choose a valid delivery date and time.');
        $now=new \DateTimeImmutable('now',$timezone);
        if($local<$now->modify('+'.max(0,(int)$settings->minimum_order_notice_hours).' hours')) throw new \DomainException('This order requires at least '.(int)$settings->minimum_order_notice_hours.' hours notice.');
        $start=\DateTimeImmutable::createFromFormat('!H:i:s',(string)$settings->delivery_start_time,$timezone);
        $end=\DateTimeImmutable::createFromFormat('!H:i:s',(string)$settings->delivery_end_time,$timezone);
        $deliveryClock=\DateTimeImmutable::createFromFormat('!H:i:s',$local->format('H:i:s'),$timezone);
        if($deliveryClock<$start||$deliveryClock>$end) throw new \DomainException('Delivery is available between '.date('g:i A',strtotime((string)$settings->delivery_start_time)).' and '.date('g:i A',strtotime((string)$settings->delivery_end_time)).'.');
        $weekdays=array_filter(array_map('intval',explode(',',(string)$settings->available_weekdays)));
        if(!in_array((int)$local->format('N'),$weekdays,true)) throw new \DomainException('Delivery is not available on that weekday.');
        $blackouts=json_decode((string)($settings->blackout_dates_json??'[]'),true)?:[];
        if(in_array($local->format('Y-m-d'),$blackouts,true)) throw new \DomainException('Delivery is unavailable on the selected date.');
        return ['local'=>$local->format('Y-m-d H:i:s'),'utc'=>$local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'timezone'=>$timezone->getName()];
    }
}
