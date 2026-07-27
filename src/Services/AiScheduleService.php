<?php
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AiScheduleService
{
    /**
     * Supported: DAILY HH:MM [Timezone], WEEKLY MON HH:MM [Timezone],
     * or a five-field cron expression followed by an optional IANA timezone.
     */
    public function next(?string $expression,?DateTimeImmutable $after=null): DateTimeImmutable
    {
        $expression=trim((string)$expression);$after??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if(preg_match('/^DAILY\s+(\d{1,2}):(\d{2})(?:\s+(.+))?$/i',$expression,$m)){
            $tz=$this->timezone($m[3]??'America/New_York');$local=$after->setTimezone($tz);
            $next=$local->setTime((int)$m[1],(int)$m[2],0);if($next<=$local)$next=$next->modify('+1 day');
            return $next->setTimezone(new DateTimeZone('UTC'));
        }
        if(preg_match('/^WEEKLY\s+(MON|TUE|WED|THU|FRI|SAT|SUN)\s+(\d{1,2}):(\d{2})(?:\s+(.+))?$/i',$expression,$m)){
            $tz=$this->timezone($m[4]??'America/New_York');$local=$after->setTimezone($tz);
            $next=$local->modify('next '.strtolower($m[1]))->setTime((int)$m[2],(int)$m[3],0);
            return $next->setTimezone(new DateTimeZone('UTC'));
        }
        $parts=preg_split('/\s+/',$expression);
        if(count($parts)>=5&&preg_match('/^[\d*\/,\-]+$/',implode('',array_slice($parts,0,5)))){
            $tz=count($parts)>5?$this->timezone(implode(' ',array_slice($parts,5))):new DateTimeZone('America/New_York');
            $cursor=$after->setTimezone($tz)->modify('+1 minute')->setTime((int)$after->setTimezone($tz)->modify('+1 minute')->format('H'),(int)$after->setTimezone($tz)->modify('+1 minute')->format('i'),0);
            for($i=0;$i<527040;$i++,$cursor=$cursor->modify('+1 minute'))if($this->cronMatches(array_slice($parts,0,5),$cursor))return $cursor->setTimezone(new DateTimeZone('UTC'));
        }
        throw new InvalidArgumentException('Schedule format: DAILY 09:00 America/New_York, WEEKLY MON 09:00 America/New_York, or 5-field cron plus timezone.');
    }
    private function timezone(string $name): DateTimeZone{try{return new DateTimeZone(trim($name));}catch(\Throwable){throw new InvalidArgumentException('Invalid schedule timezone.');}}
    private function cronMatches(array $p,DateTimeImmutable $d): bool
    {
        $values=[(int)$d->format('i'),(int)$d->format('G'),(int)$d->format('j'),(int)$d->format('n'),(int)$d->format('w')];
        foreach($p as $i=>$field)if(!$this->fieldMatches($field,$values[$i]))return false;return true;
    }
    private function fieldMatches(string $field,int $value): bool
    {
        foreach(explode(',',$field) as $part){$step=1;if(str_contains($part,'/')){[$part,$stepRaw]=explode('/',$part,2);$step=max(1,(int)$stepRaw);}
            if($part==='*'){if($value%$step===0)return true;continue;}
            if(str_contains($part,'-')){[$a,$b]=array_map('intval',explode('-',$part,2));if($value>=$a&&$value<=$b&&(($value-$a)%$step===0))return true;}
            elseif((int)$part===$value)return true;
        }return false;
    }
}
