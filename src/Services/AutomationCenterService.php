<?php

namespace App\Services;

use App\Repositories\Connection;

final class AutomationCenterService
{
    private Connection $db;
    private int $ownerId;
    private string $siteKey;
    private object $settings;
    private \DateTimeZone $timezone;

    public function __construct(int $ownerId=2,string $siteKey='vnvevents',?Connection $db=null)
    {
        $this->db=$db??new Connection();$this->ownerId=$ownerId;$this->siteKey=$siteKey;
        $this->settings=$this->loadSettings();
        try{$this->timezone=new \DateTimeZone((string)$this->settings->timezone);}catch(\Throwable){$this->timezone=new \DateTimeZone('America/New_York');}
    }

    public function settings(): object{return $this->settings;}

    public function mascotState(int $pending=0): array
    {
        $today=new \DateTimeImmutable('today',$this->timezone);$md=$today->format('m-d');$state=$pending>0?'coffee':'sad';$greeting='';
        if($md==='12-01')$greeting='Psst… remember Jonathan’s birthday is coming up — but don’t tell him I reminded you!';
        elseif($md==='11-26')$greeting='A tiny reminder: today is Mary’s birthday. 💛';
        if($md==='10-31'){$state='halloween';$greeting='Happy Halloween!';}
        elseif($today->format('m')==='12'){$state='reindeer';$greeting=$greeting?:'Happy holidays from your VNV concierge!';}
        elseif($md==='02-14'){$state='cupid';$greeting='Happy Valentine’s Day!';}
        else{$easter=(new \DateTimeImmutable('@'.easter_date((int)$today->format('Y'))))->setTimezone($this->timezone);if(abs((int)$today->diff($easter)->format('%r%a'))<=1){$state='easter';$greeting='Happy Easter!';}}
        return ['state'=>$state,'greeting'=>$greeting];
    }

    public function saveSettings(array $input,int $actorId): void
    {
        $bools=['master_enabled','dry_run','contract_reminders_enabled','payment_reminders_enabled','team_reminders_enabled','rewards_messages_enabled','ai_copy_enabled'];
        $data=[];foreach($bools as $key)$data[$key]=isset($input[$key])?1:0;
        $timezone=trim((string)($input['timezone']??'America/New_York'));new \DateTimeZone($timezone);
        $this->db->query("UPDATE automation_settings SET master_enabled=:master,dry_run=:dry,contract_reminders_enabled=:contracts,payment_reminders_enabled=:payments,team_reminders_enabled=:team,rewards_messages_enabled=:rewards,ai_copy_enabled=:ai,timezone=:timezone,quiet_hours_start=:quiet_start,quiet_hours_end=:quiet_end,max_messages_per_user_day=:daily,max_messages_per_user_week=:weekly,contract_cadence_days=:contract_days,payment_cadence_days=:payment_days,rewards_cadence_days=:reward_days,promotion_note=:promotion,test_recipient_email=:test_email,updated_by=:actor WHERE id_owner=:owner AND site_key=:site");
        $values=['master'=>$data['master_enabled'],'dry'=>$data['dry_run'],'contracts'=>$data['contract_reminders_enabled'],'payments'=>$data['payment_reminders_enabled'],'team'=>$data['team_reminders_enabled'],'rewards'=>$data['rewards_messages_enabled'],'ai'=>$data['ai_copy_enabled'],'timezone'=>$timezone,'quiet_start'=>$this->time($input['quiet_hours_start']??'20:00'),'quiet_end'=>$this->time($input['quiet_hours_end']??'09:00'),'daily'=>max(1,min(10,(int)($input['max_messages_per_user_day']??2))),'weekly'=>max(1,min(20,(int)($input['max_messages_per_user_week']??4))),'contract_days'=>max(1,min(30,(int)($input['contract_cadence_days']??5))),'payment_days'=>max(1,min(30,(int)($input['payment_cadence_days']??4))),'reward_days'=>max(7,min(180,(int)($input['rewards_cadence_days']??30))),'promotion'=>trim((string)($input['promotion_note']??''))?:null,'test_email'=>filter_var((string)($input['test_recipient_email']??''),FILTER_VALIDATE_EMAIL)?:null,'actor'=>$actorId,'owner'=>$this->ownerId,'site'=>$this->siteKey];
        foreach($values as $key=>$value)$this->db->bind(':'.$key,$value);$this->db->execute();$this->settings=$this->loadSettings();
    }

    public function runScheduler(string $trigger='CRON'): array
    {
        $run=$this->startRun('automation-scheduler',$trigger);$result=['scanned'=>0,'queued'=>0,'skipped'=>0,'run_id'=>$run];
        try{
            if(!(int)$this->settings->master_enabled){$this->finishRun($run,'SKIPPED',$result,'Master switch is off.');return $result+['status'=>'SKIPPED'];}
            if($this->inQuietHours()){$this->finishRun($run,'SKIPPED',$result,'Quiet hours.');return $result+['status'=>'SKIPPED'];}
            if((int)$this->settings->contract_reminders_enabled)$this->scanOrders('CONTRACT',$result);
            if((int)$this->settings->payment_reminders_enabled)$this->scanOrders('PAYMENT',$result);
            if((int)$this->settings->team_reminders_enabled)$this->scanTeam($result);
            if((int)$this->settings->rewards_messages_enabled)$this->scanRewards($result);
            $this->finishRun($run,'SUCCESS',$result);return $result+['status'=>'SUCCESS'];
        }catch(\Throwable $e){$this->finishRun($run,'FAILED',$result,$e->getMessage());throw $e;}
    }

    public function queueTest(string $email,int $userId): int
    {
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('A valid test email is required.');
        return $this->enqueue($userId,'EMAIL','SYSTEM_TEST','test:'.date('YmdHi').':'.$email,$email,'VNV automation test','<p>Hello,</p><p>This controlled test confirms that the VNV automation outbox is operating. No client or employee was contacted.</p>','/panel/planner-hub/settings/automation','PENDING');
    }

    public function isReviewer(int $userId): bool
    {
        if($userId===$this->ownerId)return true;
        $this->db->query("SELECT 1 FROM automation_reviewers WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND status='ACTIVE' LIMIT 1");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':user',$userId);return (bool)$this->db->fetchOne();
    }

    public function pendingReview(int $limit=40): array
    {
        $this->db->query("SELECT o.dedupe_key,MIN(o.id) id,MIN(o.id_user) id_user,MIN(o.message_type) message_type,MIN(o.subject) subject,MIN(o.body) body,MIN(o.action_url) action_url,MIN(o.created_at) created_at,GROUP_CONCAT(o.channel ORDER BY o.channel) channels,u.name,u.lastname,u.email FROM automation_outbox o LEFT JOIN users u ON u.id=o.id_user WHERE o.id_owner=:owner AND o.site_key=:site AND o.status='AWAITING_APPROVAL' GROUP BY o.dedupe_key,u.name,u.lastname,u.email ORDER BY created_at LIMIT ".max(1,min(100,$limit)));$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);return $this->db->fetchAll();
    }

    public function review(string $dedupe,string $decision,int $reviewerId,string $comment='',?string $memoryType=null,?string $memory=''): void
    {
        if(!$this->isReviewer($reviewerId))throw new \RuntimeException('This user is not an active automation reviewer.');if(!in_array($decision,['APPROVE','REJECT'],true))throw new \InvalidArgumentException('Invalid review decision.');
        $this->db->query("SELECT MIN(id_user) id_user FROM automation_outbox WHERE id_owner=:owner AND site_key=:site AND dedupe_key=:dedupe AND status='AWAITING_APPROVAL'");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':dedupe',$dedupe);$row=$this->db->fetchOne();if(!$row)throw new \RuntimeException('This recommendation was already reviewed.');
        $status=$decision==='APPROVE'?'PENDING':'CANCELLED';$this->db->query("UPDATE automation_outbox SET status=:status,reviewed_by=:reviewer,reviewed_at=UTC_TIMESTAMP(),reviewer_comment=:comment WHERE id_owner=:owner AND site_key=:site AND dedupe_key=:dedupe AND status='AWAITING_APPROVAL'");foreach(['status'=>$status,'reviewer'=>$reviewerId,'comment'=>$comment?:null,'owner'=>$this->ownerId,'site'=>$this->siteKey,'dedupe'=>$dedupe] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();
        $text=($decision==='APPROVE'?'Approved':'Removed').' recommendation '.$dedupe.($comment!==''?' — '.$comment:'');$this->db->query("INSERT INTO automation_conversations (id_owner,site_key,thread_key,id_customer,id_author,author_type,message) VALUES (:owner,:site,:thread,:customer,:author,'HUMAN',:message)");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'thread'=>'review:'.$dedupe,'customer'=>(int)$row->id_user?:null,'author'=>$reviewerId,'message'=>$text] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();$conversationId=(int)$this->db->lastId();
        if($memory!==''&&in_array($memoryType,['FACT','PREFERENCE','SENSITIVITY','INFERENCE'],true)&&(int)$row->id_user>0){$confidence=$memoryType==='INFERENCE'?0.6000:1.0000;$this->db->query("INSERT INTO automation_customer_memory (id_owner,site_key,id_customer,memory_type,summary,confidence,source_conversation_id,created_by) VALUES (:owner,:site,:customer,:type,:summary,:confidence,:conversation,:author)");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'customer'=>(int)$row->id_user,'type'=>$memoryType,'summary'=>$memory,'confidence'=>$confidence,'conversation'=>$conversationId,'author'=>$reviewerId] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
    }

    public function processOutbox(int $limit=30): array
    {
        $run=$this->startRun('automation-delivery-worker','CRON');$stats=['scanned'=>0,'sent'=>0,'failed'=>0,'simulated'=>0];
        try{
            $this->db->query("UPDATE automation_outbox SET status='FAILED',last_error='Recovered after stale worker lock',locked_at=NULL,available_at=UTC_TIMESTAMP() WHERE id_owner=:owner AND site_key=:site AND status='PROCESSING' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->execute();
            $this->db->query("SELECT * FROM automation_outbox WHERE id_owner=:owner AND site_key=:site AND status IN ('PENDING','FAILED') AND available_at<=UTC_TIMESTAMP() AND attempts<4 ORDER BY id LIMIT ".max(1,min(100,$limit)));
            $this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$rows=$this->db->fetchAll();
            foreach($rows as $row){$stats['scanned']++;if(!$this->claim((int)$row->id))continue;
                if((int)$this->settings->dry_run){$this->mark((int)$row->id,'SIMULATED');$stats['simulated']++;continue;}
                try{$reference=$this->deliver($row);$this->mark((int)$row->id,'SENT',null,$reference);$stats['sent']++;}catch(\Throwable $e){$delay=min(360,15*(2**min(4,(int)$row->attempts)));$this->mark((int)$row->id,'FAILED',$e->getMessage(),null,$delay);$stats['failed']++;}
            }
            $status=$stats['failed']?'PARTIAL':'SUCCESS';$this->finishRun($run,$status,['scanned'=>$stats['scanned'],'queued'=>0,'sent'=>$stats['sent'],'failed'=>$stats['failed']]);return $stats+['status'=>$status];
        }catch(\Throwable $e){$this->finishRun($run,'FAILED',[],$e->getMessage());throw $e;}
    }

    public function dashboard(): array
    {
        $this->db->query("SELECT status,COUNT(*) total FROM automation_outbox WHERE id_owner=:owner AND site_key=:site GROUP BY status");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$counts=[];foreach($this->db->fetchAll() as $r)$counts[$r->status]=(int)$r->total;
        $this->db->query("SELECT * FROM automation_runs WHERE id_owner=:owner AND site_key=:site ORDER BY id DESC LIMIT 30");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$runs=$this->db->fetchAll();
        $this->db->query("SELECT id,channel,message_type,recipient,status,attempts,last_error,available_at,sent_at,created_at FROM automation_outbox WHERE id_owner=:owner AND site_key=:site ORDER BY id DESC LIMIT 40");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$outbox=$this->db->fetchAll();
        return ['counts'=>$counts,'runs'=>$runs,'outbox'=>$outbox];
    }

    private function scanOrders(string $kind,array &$result): void
    {
        $statuses=$kind==='CONTRACT'?"'INVOICE_DRAFT'":"'INVOICE_READY','INVOICE_PARTIAL'";$cadence=(int)($kind==='CONTRACT'?$this->settings->contract_cadence_days:$this->settings->payment_cadence_days);
        $this->db->query("SELECT o.id,o.id_client,o.event_date,o.start_time,o.address,o.status_workflow,u.name,u.lastname,u.email,u.expo_token FROM orders o JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date>=CURDATE() AND o.status_workflow IN ($statuses) ORDER BY o.event_date LIMIT 250");$this->db->bind(':owner',$this->ownerId);$rows=$this->db->fetchAll();
        foreach($rows as $row){$result['scanned']++;$bucket=(int)floor((new \DateTimeImmutable('today',$this->timezone))->diff(new \DateTimeImmutable((string)$row->event_date,$this->timezone))->days/max(1,$cadence));$type=$kind==='CONTRACT'?'CONTRACT_SIGNATURE':'PAYMENT_DUE';$token=$this->orderToken((int)$row->id,(int)$row->id_client);$url='/order-access?token='.rawurlencode($token);$subject=$kind==='CONTRACT'?'A gentle reminder: your VNV event contract':'A gentle reminder about your VNV event payment';$verb=$kind==='CONTRACT'?'review and sign your contract':'review your pending payment';$body='<p>Hello '.htmlspecialchars(trim($row->name.' '.$row->lastname)).',</p><p>We are looking forward to your event on <strong>'.htmlspecialchars(date('F j, Y',strtotime($row->event_date))).'</strong>. When convenient, please '.$verb.'.</p><p><a href="'.htmlspecialchars($this->absolute($url)).'">Open your secure event page</a></p><p>If you have already completed this step, no action is needed.</p>';$message='Your VNV event on '.date('M j',strtotime($row->event_date)).' has a pending '.strtolower(str_replace('_',' ',$type)).'.';$result['queued']+=$this->queueUser((int)$row->id_client,$row->email??null,$row->expo_token??null,$type,strtolower($type).':'.$row->id.':'.$bucket,$subject,$body,$message,$url,false);
        }
    }

    private function scanRewards(array &$result): void
    {
        $this->db->query("SELECT lt.id_user,SUM(lt.points) points,MAX(lt.released_at) released_at,u.name,u.email,u.expo_token FROM loyalty_transactions lt JOIN users u ON u.id=lt.id_user WHERE lt.id_owner=:owner AND lt.site_key=:site AND lt.status IN ('AVAILABLE','REDEEMED') GROUP BY lt.id_user,u.name,u.email,u.expo_token HAVING points>0 ORDER BY released_at DESC LIMIT 250");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$rows=$this->db->fetchAll();
        $cadence=max(7,(int)$this->settings->rewards_cadence_days);$bucket=(int)floor((int)(new \DateTimeImmutable('today',$this->timezone))->format('U')/86400/$cadence);
        foreach($rows as $row){$result['scanned']++;$url='/panel/rewards';$points=rtrim(rtrim(number_format((float)$row->points,2,'.',''), '0'),'.');$subject='A little VNV reminder: your rewards are ready';$promotion=trim((string)($this->settings->promotion_note??''));$extra=$promotion!==''?'<p>'.htmlspecialchars($promotion).'</p>':'';$body='<p>Hello '.htmlspecialchars((string)$row->name).',</p><p>You have <strong>'.$points.' VNV points</strong> available. Use them whenever the moment feels right—there is no need to act today.</p>'.$extra.'<p><a href="'.htmlspecialchars($this->absolute($url)).'">View rewards</a></p>';$message='You have '.$points.' VNV points available.'.($promotion!==''?' '.$promotion:'');$result['queued']+=$this->queueUser((int)$row->id_user,$row->email??null,$row->expo_token??null,'REWARDS_AVAILABLE','reward-balance:'.$row->id_user.':'.$bucket,$subject,$body,$message,$url,true);}
    }

    private function scanTeam(array &$result): void
    {
        $this->db->query("SELECT DISTINCT ott.id_user,o.id order_id,o.event_date,o.start_time,u.email,u.expo_token,u.name FROM orders_team_tasks ott JOIN orders o ON o.id=ott.id_order JOIN users u ON u.id=ott.id_user WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 1 DAY) LIMIT 250");$this->db->bind(':owner',$this->ownerId);$rows=$this->db->fetchAll();
        foreach($rows as $row){$result['scanned']++;$day=$row->event_date===date('Y-m-d')?'today':'tomorrow';$url='/panel/event-execution?order_id='.(int)$row->order_id;$subject='VNV event assignment '.$day;$body='<p>Hello '.htmlspecialchars((string)$row->name).',</p><p>A friendly reminder that you are assigned to VNV event #'.(int)$row->order_id.' '.$day.' at '.htmlspecialchars(date('g:i A',strtotime($row->start_time))).'.</p><p><a href="'.htmlspecialchars($this->absolute($url)).'">Open event details</a></p>';$message='Event #'.(int)$row->order_id.' is '.$day.' at '.date('g:i A',strtotime($row->start_time)).'.';$result['queued']+=$this->queueUser((int)$row->id_user,$row->email??null,$row->expo_token??null,'TEAM_EVENT_REMINDER','team-event:'.$row->order_id.':'.$row->id_user.':'.$row->event_date,$subject,$body,$message,$url,false);}
    }

    private function queueUser(int $userId,?string $email,?string $token,string $type,string $dedupe,string $subject,string $html,string $message,string $url,bool $marketing): int
    {
        if(!$this->withinLimits($userId)||!$this->allowed($userId,$marketing))return 0;$n=0;
        if($email)$n+=$this->enqueue($userId,'EMAIL',$type,$dedupe,$email,$subject,$html,$url);
        if($token)$n+=$this->enqueue($userId,'PUSH',$type,$dedupe,$token,$subject,$message,$url);
        $n+=$this->enqueue($userId,'IN_APP',$type,$dedupe,(string)$userId,$subject,$message,$url);return $n;
    }

    private function enqueue(?int $userId,string $channel,string $type,string $dedupe,?string $recipient,?string $subject,string $body,?string $url,string $status='AWAITING_APPROVAL'): int
    {
        $this->db->query("INSERT IGNORE INTO automation_outbox (id_owner,site_key,id_user,channel,message_type,dedupe_key,recipient,subject,body,action_url,status,available_at) VALUES (:owner,:site,:user,:channel,:type,:dedupe,:recipient,:subject,:body,:url,:status,UTC_TIMESTAMP())");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId,'channel'=>$channel,'type'=>$type,'dedupe'=>$dedupe,'recipient'=>$recipient,'subject'=>$subject,'body'=>$body,'url'=>$url,'status'=>$status] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();return $this->db->rowCount()>0?1:0;
    }

    private function deliver(object $row): ?string
    {
        if($row->channel==='EMAIL'){$r=EmailServiceFactory::sendWithOwnerProvider($this->ownerId,(string)$row->recipient,(string)$row->subject,(string)$row->body,true);if(!($r['success']??false))throw new \RuntimeException((string)($r['message']??'Email failed'));return (string)($r['message']??'email');}
        if($row->channel==='PUSH'){$r=NotificationService::sendExpoNotificationWithResult((string)$row->recipient,(string)$row->subject,(string)$row->body,['url'=>$row->action_url,'route'=>$row->action_url,'type'=>$row->message_type]);if(!($r['ok']??false))throw new \RuntimeException(json_encode($r));return $r['ticket_id']??'push';}
        $this->db->query("INSERT INTO notifications (id_user,mensaje,link,leido) VALUES (:user,:message,:link,'NO')");$this->db->bind(':user',(int)$row->id_user);$this->db->bind(':message',(string)$row->body);$this->db->bind(':link',(string)$row->action_url);$this->db->execute();return 'in-app';
    }

    private function loadSettings(): object{$this->db->query("SELECT * FROM automation_settings WHERE id_owner=:owner AND site_key=:site LIMIT 1");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$row=$this->db->fetchOne();if(!$row)throw new \RuntimeException('Run db/20261003_event_automation_center.sql first.');return $row;}
    private function allowed(int $userId,bool $marketing): bool{$this->db->query("SELECT * FROM automation_user_preferences WHERE id_owner=:owner AND site_key=:site AND id_user=:user LIMIT 1");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':user',$userId);$p=$this->db->fetchOne();return !$p||((int)$p->operational_enabled&&(!$marketing||(int)$p->marketing_enabled));}
    private function withinLimits(int $userId): bool{$this->db->query("SELECT SUM(created_at>=UTC_DATE()) daily,SUM(created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)) weekly FROM automation_outbox WHERE id_owner=:owner AND site_key=:site AND id_user=:user AND channel='IN_APP' AND status<>'CANCELLED'");$this->db->bind(':owner',$this->ownerId);$this->db->bind(':site',$this->siteKey);$this->db->bind(':user',$userId);$r=$this->db->fetchOne();return (int)($r->daily??0)<(int)$this->settings->max_messages_per_user_day&&(int)($r->weekly??0)<(int)$this->settings->max_messages_per_user_week;}
    private function inQuietHours(): bool{$now=new \DateTimeImmutable('now',$this->timezone);$t=$now->format('H:i:s');$start=(string)$this->settings->quiet_hours_start;$end=(string)$this->settings->quiet_hours_end;return $start>$end?($t>=$start||$t<$end):($t>=$start&&$t<$end);}
    private function claim(int $id): bool{$this->db->query("UPDATE automation_outbox SET status='PROCESSING',attempts=attempts+1,locked_at=UTC_TIMESTAMP() WHERE id=:id AND status IN ('PENDING','FAILED') AND attempts<4");$this->db->bind(':id',$id);$this->db->execute();return $this->db->rowCount()===1;}
    private function mark(int $id,string $status,?string $error=null,?string $reference=null,int $delayMinutes=0): void{$this->db->query("UPDATE automation_outbox SET status=:status,last_error=:error,provider_reference=:reference,sent_at=IF(:status IN ('SENT','SIMULATED'),UTC_TIMESTAMP(),sent_at),available_at=IF(:status='FAILED',DATE_ADD(UTC_TIMESTAMP(),INTERVAL :delay MINUTE),available_at),locked_at=NULL WHERE id=:id");foreach(['status'=>$status,'error'=>$error?substr($error,0,1000):null,'reference'=>$reference,'delay'=>$delayMinutes,'id'=>$id] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
    private function startRun(string $job,string $trigger): int{$token=$this->uuid();$this->db->query("INSERT INTO automation_runs (id_owner,site_key,job_key,run_token,trigger_type,status,started_at) VALUES (:owner,:site,:job,:token,:trigger,'RUNNING',UTC_TIMESTAMP())");foreach(['owner'=>$this->ownerId,'site'=>$this->siteKey,'job'=>$job,'token'=>$token,'trigger'=>$trigger] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();return (int)$this->db->lastId();}
    private function finishRun(int $id,string $status,array $stats,?string $error=null): void{$this->db->query("UPDATE automation_runs SET status=:status,scanned_count=:scanned,queued_count=:queued,sent_count=:sent,failed_count=:failed,details_json=:details,error_message=:error,finished_at=UTC_TIMESTAMP() WHERE id=:id");foreach(['status'=>$status,'scanned'=>(int)($stats['scanned']??0),'queued'=>(int)($stats['queued']??0),'sent'=>(int)($stats['sent']??0),'failed'=>(int)($stats['failed']??0),'details'=>json_encode($stats),'error'=>$error?substr($error,0,1000):null,'id'=>$id] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();}
    private function orderToken(int $orderId,int $userId): string{$payload=['order_id'=>$orderId,'user_id'=>$userId,'exp'=>time()+86400*30];$payload['hash']=hash_hmac('sha256',json_encode($payload),$_ENV['VNV_SECRET_KEY']??'mySuperSecretKey');return base64_encode(json_encode($payload));}
    private function absolute(string $url): string{return rtrim($_ENV['APP_URL']??'https://vnvevents.com','/').'/'.ltrim($url,'/');}
    private function time(string $value): string{$d=\DateTimeImmutable::createFromFormat('H:i',$value);return $d?$d->format('H:i:s'):'09:00:00';}
    private function uuid(): string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
