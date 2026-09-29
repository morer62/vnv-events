<?php

namespace App\Services;

use App\Repositories\Connection;

final class SocialEditorialPublishingWorker
{
    public function run(int $limit=20): array
    {
        $db=new Connection();$limit=max(1,min(100,$limit));$db->query("SELECT * FROM social_editorial_publications WHERE state='SCHEDULED' AND scheduled_for<=NOW() ORDER BY scheduled_for,id LIMIT {$limit}");$rows=$db->fetchAll();$result=['reviewed'=>count($rows),'published'=>0,'retry'=>0,'failed'=>0,'items'=>[]];
        foreach($rows as $row){$db->query("UPDATE social_editorial_publications SET state='SELECTED' WHERE id=:id AND state='SCHEDULED'");$db->bind(':id',(int)$row->id);$db->execute();if($db->rowCount()!==1)continue;
            try{$db->query("SELECT c.id FROM ai_agent_connections c JOIN ai_agents a ON a.id=c.id_agent WHERE c.id_owner=2 AND c.site_key=:site AND c.platform=:platform AND c.status='VERIFIED' AND a.agent_key='social_publisher' LIMIT 1");$db->bind(':site',(string)$row->site_key);$db->bind(':platform',(string)$row->network);if(!$db->fetchOne())throw new \RuntimeException(ucfirst((string)$row->network).' official Page connection is not verified for '.(string)$row->site_key.'.');$payload=['copy'=>(string)$row->copy_text,'hashtags'=>json_decode((string)$row->hashtags_json,true)?:[],'image_url'=>(string)$row->image_url,'article_url'=>(string)$row->article_url,'link'=>(string)$row->article_url,'title'=>(string)$row->headline];$published=(new SocialPublishingService(null,(string)$row->site_key))->publish(2,(string)$row->network,$payload);$external=(string)($published['id']??$published['post_id']??'');
                $db->query("UPDATE social_editorial_publications SET state='PUBLISHED',published_at=NOW(),external_post_id=:external,last_error=NULL WHERE id=:id");$db->bind(':external',$external?:null);$db->bind(':id',(int)$row->id);$db->execute();$result['published']++;$result['items'][]=['id'=>(int)$row->id,'state'=>'PUBLISHED','external_post_id'=>$external];
            }catch(\Throwable $e){$transient=(bool)preg_match('/timeout|temporar|rate limit|429|500|502|503|504/i',$e->getMessage());$retry=$transient&&(int)$row->retry_count<2;$delay=2**((int)$row->retry_count+1);$db->query("UPDATE social_editorial_publications SET state=:state,retry_count=retry_count+1,last_error=:error,scheduled_for=".($retry?'DATE_ADD(NOW(),INTERVAL '.$delay.' MINUTE)':'scheduled_for')." WHERE id=:id");$db->bind(':state',$retry?'SCHEDULED':'FAILED');$db->bind(':error',mb_substr($e->getMessage(),0,2000));$db->bind(':id',(int)$row->id);$db->execute();$result[$retry?'retry':'failed']++;$result['items'][]=['id'=>(int)$row->id,'state'=>$retry?'SCHEDULED':'FAILED','error'=>$e->getMessage()];}
        }return $result;
    }
}
