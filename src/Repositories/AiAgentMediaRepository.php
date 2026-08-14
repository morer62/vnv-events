<?php

namespace App\Repositories;

class AiAgentMediaRepository
{
    public Connection $db;
    public function __construct() { $this->db = new Connection(); }

    public function add(int $ownerId, int $userId, array $data): int
    {
        $this->db->query("INSERT INTO ai_agent_media_jobs
          (id_owner,id_user,title,source_url,source_name,mime_type,status)
          VALUES (:owner,:user,:title,:url,:name,:mime,'UPLOADED')");
        foreach (['owner'=>$ownerId,'user'=>$userId,'title'=>$data['title'],'url'=>$data['source_url'],'name'=>$data['source_name'],'mime'=>$data['mime_type']] as $key=>$value) {
            $this->db->bind(':'.$key,$value);
        }
        $this->db->execute();
        return (int)$this->db->lastId();
    }

    public function all(int $ownerId): array
    {
        $this->db->query("SELECT * FROM ai_agent_media_jobs WHERE id_owner=:owner ORDER BY id DESC LIMIT 100");
        $this->db->bind(':owner',$ownerId);
        return $this->db->fetchAll();
    }

    public function find(int $ownerId,int $id): ?object
    {
        $this->db->query("SELECT * FROM ai_agent_media_jobs WHERE id_owner=:owner AND id=:id LIMIT 1");
        $this->db->bind(':owner',$ownerId); $this->db->bind(':id',$id);
        return $this->db->fetchOne() ?: null;
    }

    public function sourceExists(int $ownerId, string $sourceUrl): bool
    {
        $this->db->query("SELECT id FROM ai_agent_media_jobs WHERE id_owner=:owner AND source_url=:url LIMIT 1");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':url', $sourceUrl);
        return (bool)$this->db->fetchOne();
    }

    public function updateTranscript(int $ownerId,int $id,array $result): void
    {
        $this->db->query("UPDATE ai_agent_media_jobs SET status='READY',transcript_text=:text,
          transcript_json=:json,subtitles_srt=:srt,error_message=NULL WHERE id=:id AND id_owner=:owner");
        $this->db->bind(':text',$result['text']); $this->db->bind(':json',json_encode($result['raw'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $this->db->bind(':srt',$result['srt']); $this->db->bind(':id',$id); $this->db->bind(':owner',$ownerId); $this->db->execute();
    }

    public function updateEditor(int $ownerId, int $id, string $transcript, string $subtitles, ?array $editPlan = null): void
    {
        $sql = "UPDATE ai_agent_media_jobs SET transcript_text=:text,subtitles_srt=:srt";
        if ($editPlan !== null) {
            $sql .= ",edit_plan_json=:plan";
        }
        $sql .= " WHERE id=:id AND id_owner=:owner";
        $this->db->query($sql);
        $this->db->bind(':text', $transcript);
        $this->db->bind(':srt', $subtitles);
        if ($editPlan !== null) {
            $this->db->bind(':plan', json_encode($editPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->db->bind(':id', $id);
        $this->db->bind(':owner', $ownerId);
        $this->db->execute();
    }

    public function revision(int $ownerId,int $userId,object $job,string $type,string $notes=''): void
    {
        $this->db->query("INSERT INTO ai_agent_video_revisions(id_media_job,id_owner,id_user,revision_type,transcript_text,subtitles_srt,edit_plan_json,notes)
          VALUES(:job,:owner,:user,:type,:text,:srt,:plan,:notes)");
        foreach(['job'=>(int)$job->id,'owner'=>$ownerId,'user'=>$userId,'type'=>$type,'text'=>$job->transcript_text,'srt'=>$job->subtitles_srt,'plan'=>$job->edit_plan_json,'notes'=>$notes] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();
    }
    public function revisions(int $ownerId,int $id): array{$this->db->query("SELECT id,revision_type,notes,created_at FROM ai_agent_video_revisions WHERE id_owner=:owner AND id_media_job=:id ORDER BY id DESC LIMIT 25");$this->db->bind(':owner',$ownerId);$this->db->bind(':id',$id);return $this->db->fetchAll();}
    public function rename(int $ownerId,int $id,string $title): bool
    {
        $title=trim($title);if($title==='')return false;
        $this->db->query("UPDATE ai_agent_media_jobs SET title=:title WHERE id=:id AND id_owner=:owner");
        $this->db->bind(':title',mb_substr($title,0,180));$this->db->bind(':id',$id);$this->db->bind(':owner',$ownerId);$this->db->execute();
        return $this->db->rowCount()>0;
    }

    public function duplicate(int $ownerId,int $userId,int $id): int
    {
        $job=$this->find($ownerId,$id);if(!$job)return 0;$this->db->query("INSERT INTO ai_agent_media_jobs(id_owner,id_user,title,source_url,source_name,mime_type,status,transcript_text,transcript_json,subtitles_srt,edit_plan_json)
          VALUES(:owner,:user,:title,:url,:name,:mime,:status,:text,:json,:srt,:plan)");
        $status=trim((string)$job->transcript_text)!==''?'READY':'UPLOADED';
        $this->db->bind(':status',$status);
        foreach(['owner'=>$ownerId,'user'=>$userId,'title'=>$job->title.' — remix','url'=>$job->source_url,'name'=>$job->source_name,'mime'=>$job->mime_type,'text'=>$job->transcript_text,'json'=>$job->transcript_json,'srt'=>$job->subtitles_srt,'plan'=>$job->edit_plan_json] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();return (int)$this->db->lastId();
    }

    public function createDerivedProject(int $ownerId,int $userId,object $source,string $title,array $plan): int
    {
        $this->db->query("INSERT INTO ai_agent_media_jobs(id_owner,id_user,title,source_url,source_name,mime_type,status,transcript_text,transcript_json,subtitles_srt,edit_plan_json)
          VALUES(:owner,:user,:title,:url,:name,:mime,'READY',:text,:json,:srt,:plan)");
        foreach(['owner'=>$ownerId,'user'=>$userId,'title'=>$title,'url'=>$source->source_url,'name'=>$source->source_name,'mime'=>$source->mime_type,'text'=>$source->transcript_text,'json'=>$source->transcript_json,'srt'=>$source->subtitles_srt,'plan'=>json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)] as $key=>$value)$this->db->bind(':'.$key,$value);
        $this->db->execute();return (int)$this->db->lastId();
    }

    public function updateRenderProgress(int $ownerId,int $id,int $percent,string $stage): void
    {
        $message='Rendering '.max(0,min(100,$percent)).'% — '.$stage;
        $this->db->query("UPDATE ai_agent_media_jobs SET error_message=:message WHERE id=:id AND id_owner=:owner AND status='RENDERING'");
        $this->db->bind(':message',$message);$this->db->bind(':id',$id);$this->db->bind(':owner',$ownerId);$this->db->execute();
    }

    public function fail(int $ownerId,int $id,string $error): void
    {
        $this->db->query("UPDATE ai_agent_media_jobs SET status='FAILED',error_message=:error WHERE id=:id AND id_owner=:owner");
        $this->db->bind(':error',$error); $this->db->bind(':id',$id); $this->db->bind(':owner',$ownerId); $this->db->execute();
    }

    public function queueRender(int $ownerId, int $id): void
    {
        $this->db->query("UPDATE ai_agent_media_jobs SET status='QUEUED',error_message=NULL WHERE id=:id AND id_owner=:owner AND transcript_text IS NOT NULL");
        $this->db->bind(':id',$id); $this->db->bind(':owner',$ownerId); $this->db->execute();
    }

    public function setRenderCaptionMode(int $ownerId,int $id,string $mode): void
    {
        $job=$this->find($ownerId,$id);if(!$job)return;$plan=json_decode((string)$job->edit_plan_json,true);if(!is_array($plan))$plan=[];
        $request=(array)($plan['_request']??[]);$request['caption_style']=$mode==='without_captions'?'none':(in_array($request['caption_style']??'', ['clean','dynamic','kinetic'],true)?$request['caption_style']:'dynamic');$request['render_caption_mode']=$mode;$plan['_request']=$request;
        $this->db->query("UPDATE ai_agent_media_jobs SET edit_plan_json=:plan WHERE id=:id AND id_owner=:owner");$this->db->bind(':plan',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$this->db->bind(':id',$id);$this->db->bind(':owner',$ownerId);$this->db->execute();
    }

    public function nextQueuedRender(): ?object
    {
        $this->db->query("SELECT * FROM ai_agent_media_jobs WHERE status='QUEUED' ORDER BY updated_at,id LIMIT 1");
        return $this->db->fetchOne() ?: null;
    }

    public function markRendering(int $id): bool
    {
        $this->db->query("UPDATE ai_agent_media_jobs SET status='RENDERING',error_message=NULL WHERE id=:id AND status='QUEUED'");
        $this->db->bind(':id',$id); $this->db->execute();return $this->db->rowCount()===1;
    }

    public function completeRender(int $ownerId, int $id, string $outputUrl): void
    {
        $this->db->query("UPDATE ai_agent_media_jobs SET status='COMPLETED',output_url=:url,error_message=NULL WHERE id=:id AND id_owner=:owner");
        $this->db->bind(':url',$outputUrl); $this->db->bind(':id',$id); $this->db->bind(':owner',$ownerId); $this->db->execute();
    }

    public function addAsset(int $ownerId,int $userId,string $type,string $name,string $url,string $mime): void
    {
        if(!in_array($type,['LOGO','INTRO','OUTRO','OVERLAY','AUDIO','IMAGE','TRANSPARENT_PNG','SHORT_VIDEO'],true))$type='OVERLAY';
        $this->db->query("INSERT INTO ai_agent_media_assets(id_owner,id_user,asset_type,name,asset_url,mime_type) VALUES(:owner,:user,:type,:name,:url,:mime)");
        foreach(['owner'=>$ownerId,'user'=>$userId,'type'=>$type,'name'=>$name,'url'=>$url,'mime'=>$mime] as $key=>$value)$this->db->bind(':'.$key,$value);$this->db->execute();
    }
    public function assets(int $ownerId): array{$this->db->query("SELECT * FROM ai_agent_media_assets WHERE id_owner=:owner ORDER BY asset_type,name");$this->db->bind(':owner',$ownerId);return $this->db->fetchAll();}
}
