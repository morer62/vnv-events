<?php

namespace App\Services;

use App\Repositories\Connection;
use App\Utils\FileUtils;
use App\Repositories\PaymentProvidersRepository;
use App\Services\Payment\PaymentProviderFactory;
use RuntimeException;

final class EventExecutionService
{
    public const DEFAULT_PHOTO_LIMIT = 6;
    private Connection $db;

    public function __construct() { $this->db = new Connection(); }

    public function findByCode(string $code): ?object
    {
        $this->db->query("SELECT s.*, o.id_client, o.event_date, o.start_time, o.end_time, o.address, TRIM(CONCAT(COALESCE(c.name,''),' ',COALESCE(c.lastname,''))) event_name FROM event_execution_spaces s JOIN orders o ON o.id=s.id_order LEFT JOIN users c ON c.id=o.id_client WHERE s.access_code=:code AND s.status<>'ARCHIVED' LIMIT 1");
        $this->db->bind(':code', $code);
        return $this->db->fetchOne() ?: null;
    }

    public function getOrCreateForOrder(int $orderId, int $actorId, int $ownerId): object
    {
        $this->db->query("SELECT s.*, o.id_client, o.event_date, o.start_time, o.end_time, o.address, TRIM(CONCAT(COALESCE(c.name,''),' ',COALESCE(c.lastname,''))) event_name FROM event_execution_spaces s JOIN orders o ON o.id=s.id_order LEFT JOIN users c ON c.id=o.id_client WHERE s.id_order=:order LIMIT 1");
        $this->db->bind(':order', $orderId);
        $space = $this->db->fetchOne();
        if ($space) { $this->syncAssignedMembers($space); return $space; }

        for ($attempt=0; $attempt<20; $attempt++) {
            $code = (string)random_int(100000, 999999);
            try {
                $this->db->query('INSERT INTO event_execution_spaces (id_order,id_owner,access_code,created_by) VALUES (:order,:owner,:code,:actor)');
                $this->db->bind(':order',$orderId); $this->db->bind(':owner',$ownerId); $this->db->bind(':code',$code); $this->db->bind(':actor',$actorId);
                $this->db->execute();
                $space = $this->findByCode($code) ?? throw new RuntimeException('Could not load event area.');
                $this->syncAssignedMembers($space);
                return $space;
            } catch (\PDOException $e) { if ((string)$e->getCode() !== '23000') throw $e; }
        }
        throw new RuntimeException('Could not generate a unique event code.');
    }

    public function syncAssignedMembers(object $space): void
    {
        if ((int)($space->id_client ?? 0) > 0) {
            $this->db->query("INSERT INTO event_execution_members (id_space,id_user,role) VALUES (:space,:user,'CLIENT') ON DUPLICATE KEY UPDATE role=IF(role='PARTICIPANT','CLIENT',role)");
            $this->db->bind(':space',(int)$space->id);$this->db->bind(':user',(int)$space->id_client);$this->db->execute();
        }
        $this->db->query("INSERT INTO event_execution_members (id_space,id_user,role) SELECT :space,i.id_user,'TEAM' FROM orders_staff_invites i WHERE i.id_order=:order AND i.is_confirmed=1 ON DUPLICATE KEY UPDATE role=IF(role IN ('PARTICIPANT','CLIENT'),'TEAM',role)");
        $this->db->bind(':space',(int)$space->id);$this->db->bind(':order',(int)$space->id_order);$this->db->execute();
    }

    public function canOpen(object $space, object $user): bool
    {
        $level=(int)$user->getLevel(); $uid=(int)$user->getId();
        if ($level===1 || $uid===(int)$space->id_client) return true;
        if ($level===4) {
            $this->db->query('SELECT 1 FROM orders_staff_invites WHERE id_order=:order AND id_user=:user AND is_confirmed=1 LIMIT 1');
            $this->db->bind(':order',(int)$space->id_order); $this->db->bind(':user',$uid);
            if ($this->db->fetchOne()) return true;
        }
        $this->db->query('SELECT 1 FROM event_execution_members WHERE id_space=:space AND id_user=:user LIMIT 1');
        $this->db->bind(':space',(int)$space->id); $this->db->bind(':user',$uid);
        return (bool)$this->db->fetchOne();
    }

    public function join(object $space, object $user): void
    {
        $role=(int)$user->getId()===(int)$space->id_client?'CLIENT':((int)$user->getLevel()===4?'TEAM':((int)$user->getLevel()===1?'ADMIN':'PARTICIPANT'));
        $this->db->query('INSERT INTO event_execution_members (id_space,id_user,role) VALUES (:space,:user,:role) ON DUPLICATE KEY UPDATE role=VALUES(role)');
        $this->db->bind(':space',(int)$space->id); $this->db->bind(':user',(int)$user->getId()); $this->db->bind(':role',$role); $this->db->execute();
    }

    public function isInteractionOpen(object $space): bool
    {
        if (($space->status ?? '') !== 'ACTIVE' || empty($space->event_date)) return false;
        try {
            $timezone=new \DateTimeZone('America/New_York');
            $now=new \DateTimeImmutable('now',$timezone);
            $start=(new \DateTimeImmutable((string)$space->event_date,$timezone))->setTime(0,0);
            $end=$start->modify('+'.max(0,(int)($space->interaction_days_after??1)).' days')->setTime(23,59,59);
            return $now >= $start && $now <= $end;
        } catch (\Throwable) { return false; }
    }

    public function assertCanJoin(object $space, object $user): void
    {
        if((int)$user->getLevel()===1 || (int)$user->getId()===(int)$space->id_client) return;
        if(!$this->isInteractionOpen($space)) throw new RuntimeException('This event code is outside its active participation window.');
    }

    public function assertInteractionAllowed(object $space, object $user): void
    {
        if((int)$user->getLevel()===1) return;
        if(!$this->isInteractionOpen($space)) throw new RuntimeException('This event is now read-only.');
    }

    public function dashboard(object $space): array
    {
        $this->syncAssignedMembers($space);
        $this->db->query("SELECT r.*, CONCAT(COALESCE(u.name,''),' ',COALESCE(u.lastname,'')) user_name FROM event_execution_music_requests r LEFT JOIN users u ON u.id=r.id_user WHERE r.id_space=:space AND r.status<>'CANCELLED' ORDER BY r.request_type,r.sort_order,r.created_at");
        $this->db->bind(':space',(int)$space->id); $music=$this->db->fetchAll();
        $this->db->query("SELECT p.*, CONCAT(COALESCE(u.name,''),' ',COALESCE(u.lastname,'')) uploader_name FROM event_execution_photos p LEFT JOIN users u ON u.id=p.id_user WHERE p.id_space=:space AND p.deleted_at IS NULL ORDER BY p.id_user,p.uploaded_at DESC");
        $this->db->bind(':space',(int)$space->id); $photos=$this->db->fetchAll();
        $this->db->query("SELECT m.*, CONCAT(COALESCE(u.name,''),' ',COALESCE(u.lastname,'')) member_name FROM event_execution_members m LEFT JOIN users u ON u.id=m.id_user WHERE m.id_space=:space ORDER BY FIELD(m.role,'ADMIN','DJ','TEAM','CLIENT','PARTICIPANT'),m.joined_at");
        $this->db->bind(':space',(int)$space->id); $members=$this->db->fetchAll();
        $this->db->query("SELECT t.*,CONCAT(COALESCE(sender.name,''),' ',COALESCE(sender.lastname,'')) sender_name,CONCAT(COALESCE(recipient.name,''),' ',COALESCE(recipient.lastname,'')) recipient_name FROM event_execution_tip_payments t LEFT JOIN users sender ON sender.id=t.id_user LEFT JOIN users recipient ON recipient.id=t.recipient_user_id WHERE t.id_space=:space ORDER BY t.created_at DESC");
        $this->db->bind(':space',(int)$space->id);$tips=$this->db->fetchAll();
        $this->db->query("SELECT c.*,CONCAT(COALESCE(u.name,''),' ',COALESCE(u.lastname,'')) author_name FROM event_execution_request_comments c LEFT JOIN users u ON u.id=c.id_user JOIN event_execution_music_requests r ON r.id=c.id_music_request WHERE r.id_space=:space ORDER BY c.created_at");
        $this->db->bind(':space',(int)$space->id);$comments=$this->db->fetchAll();$commentsByRequest=[];foreach($comments as $comment)$commentsByRequest[(int)$comment->id_music_request][]=$comment;
        $folders=[];
        foreach($photos as $photo){$key=(int)$photo->id_user;if(!isset($folders[$key]))$folders[$key]=['id_user'=>$key,'name'=>trim((string)$photo->uploader_name)?:'Participant','photos'=>[]];$folders[$key]['photos'][]=$photo;}
        $teamMembers=array_values(array_filter($members,fn($m)=>in_array($m->role,['TEAM','DJ'],true)));
        $tipTotal=0.0;$tipsByRecipient=[];foreach($tips as $tip){if($tip->status!=='PAID')continue;$tipTotal+=(float)$tip->amount;$key=(int)($tip->recipient_user_id??0);if($key){if(!isset($tipsByRecipient[$key]))$tipsByRecipient[$key]=['name'=>trim((string)$tip->recipient_name)?:'Team member','amount'=>0.0];$tipsByRecipient[$key]['amount']+=(float)$tip->amount;}}
        return ['karaoke'=>array_values(array_filter($music,fn($r)=>$r->request_type==='KARAOKE')),'song_requests'=>array_values(array_filter($music,fn($r)=>$r->request_type==='SONG_REQUEST')),'photos'=>$photos,'photo_folders'=>array_values($folders),'members'=>$members,'team_members'=>$teamMembers,'tips'=>$tips,'tip_total'=>$tipTotal,'tips_by_recipient'=>array_values($tipsByRecipient),'comments_by_request'=>$commentsByRequest,'modules'=>json_decode((string)($space->modules_json??'[]'),true)?:['PHOTOS','TIPS','REQUESTS','KARAOKE']];
    }

    public function stateVersion(int $spaceId): string
    {
        $this->db->query("SELECT
            (SELECT COALESCE(MAX(updated_at),'1970-01-01') FROM event_execution_music_requests WHERE id_space=:music_space) music_version,
            (SELECT COALESCE(MAX(COALESCE(deleted_at,uploaded_at)),'1970-01-01') FROM event_execution_photos WHERE id_space=:photo_space) photo_version,
            (SELECT COALESCE(MAX(joined_at),'1970-01-01') FROM event_execution_members WHERE id_space=:member_space) member_version,
            (SELECT COUNT(*) FROM event_execution_music_requests WHERE id_space=:music_count AND status<>'CANCELLED') music_count,
            (SELECT COUNT(*) FROM event_execution_photos WHERE id_space=:photo_count AND deleted_at IS NULL) photo_count,
            (SELECT COALESCE(MAX(created_at),'1970-01-01') FROM event_execution_request_comments c JOIN event_execution_music_requests r ON r.id=c.id_music_request WHERE r.id_space=:comment_space) comment_version,
            (SELECT COALESCE(MAX(created_at),'1970-01-01') FROM event_execution_tip_payments WHERE id_space=:tip_space) tip_version");
        foreach(['music_space','photo_space','member_space','music_count','photo_count','comment_space','tip_space'] as $key)$this->db->bind(':'.$key,$spaceId);
        $state=$this->db->fetchOne();
        return hash('sha256',json_encode($state));
    }

    public function addMusic(int $spaceId, object $user, array $data): void
    {
        $type=($data['request_type']??'')==='SONG_REQUEST'?'SONG_REQUEST':'KARAOKE';
        $song=trim((string)($data['song_title']??'')); if ($song==='') throw new RuntimeException('Song title is required.');
        $name=trim((string)($data['participant_name']??'')); if ($name==='') $name=trim($user->getName().' '.$user->getLastname());
        $youtube=$this->validatedYoutubeUrl((string)($data['youtube_url']??''));
        $tip=$type==='SONG_REQUEST'?max(0,(float)($data['tip_amount']??0)):0;$status=$type==='KARAOKE'?'WAITING':'PENDING';
        $this->db->query("INSERT INTO event_execution_music_requests (id_space,id_user,request_type,participant_name,song_title,artist_name,youtube_url,dedication,status,tip_amount,tip_status) VALUES (:space,:user,:type,:name,:song,:artist,:youtube,:dedication,:status,:tip,:tip_status)");
        foreach(['space'=>$spaceId,'user'=>(int)$user->getId(),'type'=>$type,'name'=>mb_substr($name,0,120),'song'=>mb_substr($song,0,180),'artist'=>mb_substr(trim((string)($data['artist_name']??'')),0,180),'youtube'=>$youtube,'dedication'=>mb_substr(trim((string)($data['dedication']??'')),0,300),'status'=>$status,'tip'=>$tip,'tip_status'=>$tip>0?'PENDING':'NONE'] as $k=>$v) $this->db->bind(':'.$k,$v);
        $this->db->execute();
    }

    public function deleteMusic(int $spaceId, int $requestId, object $user): void
    {
        $admin=$this->isMusicManager($spaceId,$user);
        $sql="UPDATE event_execution_music_requests SET status='CANCELLED' WHERE id=:id AND id_space=:space".($admin?'':' AND id_user=:user');
        $this->db->query($sql); $this->db->bind(':id',$requestId); $this->db->bind(':space',$spaceId); if(!$admin)$this->db->bind(':user',(int)$user->getId()); $this->db->execute();
    }

    public function isMusicManager(int $spaceId, object $user): bool
    {
        if ((int)$user->getLevel() === 1) return true;
        $this->db->query("SELECT 1 FROM event_execution_members WHERE id_space=:space AND id_user=:user AND role IN ('ADMIN','DJ','TEAM') LIMIT 1");
        $this->db->bind(':space',$spaceId);$this->db->bind(':user',(int)$user->getId());
        return (bool)$this->db->fetchOne();
    }

    public function updateMusic(int $spaceId, int $requestId, object $user, array $data): void
    {
        if (!$this->isMusicManager($spaceId,$user)) throw new RuntimeException('DJ or team permission is required.');
        $allowed=['PENDING','ACCEPTED','PLAYING_SOON','PLAYED','DECLINED','NEED_MORE_INFO','WAITING','UP_NEXT','SINGING','QUEUED','PLAYING','COMPLETED','CANCELLED'];
        $status=in_array(($data['status']??''),$allowed,true)?$data['status']:'PENDING';
        $song=mb_substr(trim((string)($data['song_title']??'')),0,180); if($song==='') throw new RuntimeException('Song title is required.');
        $youtube=$this->validatedYoutubeUrl((string)($data['youtube_url']??''));$reply=mb_substr(trim((string)($data['staff_reply']??'')),0,500);$decline=mb_substr(trim((string)($data['decline_reason']??'')),0,300);
        $this->db->query('UPDATE event_execution_music_requests SET song_title=:song,artist_name=:artist,youtube_url=:youtube,participant_name=:name,dedication=:dedication,staff_reply=:reply,decline_reason=:decline,status=:status,sort_order=:sort WHERE id=:id AND id_space=:space');
        foreach(['song'=>$song,'artist'=>mb_substr(trim((string)($data['artist_name']??'')),0,180),'youtube'=>$youtube,'name'=>mb_substr(trim((string)($data['participant_name']??'')),0,120),'dedication'=>mb_substr(trim((string)($data['dedication']??'')),0,300),'reply'=>$reply,'decline'=>$decline,'status'=>$status,'sort'=>(int)($data['sort_order']??0),'id'=>$requestId,'space'=>$spaceId] as $k=>$v)$this->db->bind(':'.$k,$v);
        $this->db->execute();
        $this->db->query('SELECT id_user FROM event_execution_music_requests WHERE id=:id AND id_space=:space');$this->db->bind(':id',$requestId);$this->db->bind(':space',$spaceId);$request=$this->db->fetchOne();if($request)$this->notify((int)$request->id_user,'Your event request is now '.str_replace('_',' ',strtolower($status)).'.','/panel/event-execution');
    }

    public function addRequestComment(int $spaceId,int $requestId,object $user,array $data): void
    {
        $this->db->query('SELECT * FROM event_execution_music_requests WHERE id=:id AND id_space=:space LIMIT 1');$this->db->bind(':id',$requestId);$this->db->bind(':space',$spaceId);$request=$this->db->fetchOne();
        if(!$request || ((int)$request->id_user!==(int)$user->getId() && !$this->isMusicManager($spaceId,$user))) throw new RuntimeException('You cannot reply to this request.');
        $message=mb_substr(trim((string)($data['message']??'')),0,700);$youtube=$this->validatedYoutubeUrl((string)($data['youtube_url']??''));if($message===''&&$youtube===null)throw new RuntimeException('Enter a message or YouTube link.');
        $this->db->query('INSERT INTO event_execution_request_comments (id_music_request,id_user,message,youtube_url) VALUES (:request,:user,:message,:youtube)');foreach(['request'=>$requestId,'user'=>(int)$user->getId(),'message'=>$message?:null,'youtube'=>$youtube] as $key=>$value)$this->db->bind(':'.$key,$value);$this->db->execute();
        if((int)$request->id_user!==(int)$user->getId())$this->notify((int)$request->id_user,'The event team replied to your music request.','/panel/event-execution');
    }

    public function setMemberRole(int $spaceId, int $memberId, string $role, object $user): void
    {
        if ((int)$user->getLevel()!==1) throw new RuntimeException('Only an administrator can change event roles.');
        $role=in_array($role,['CLIENT','PARTICIPANT','TEAM','DJ','ADMIN'],true)?$role:'PARTICIPANT';
        $this->db->query('UPDATE event_execution_members SET role=:role WHERE id=:id AND id_space=:space');
        $this->db->bind(':role',$role);$this->db->bind(':id',$memberId);$this->db->bind(':space',$spaceId);$this->db->execute();
    }

    public function paymentOptions(object $space, object $user): array
    {
        $provider=(new PaymentProvidersRepository())->getActiveProviderForOwner((int)$space->id_owner);
        if(!$provider || !in_array(strtolower((string)$provider->provider_type),['stripe','square'],true)) return ['provider'=>null,'methods'=>[]];
        $methods=(new ClientPaymentMethodService())->listClientSavedPaymentMethodsForProvider((int)$space->id_owner,(int)$user->getId(),strtolower((string)$provider->provider_type));
        return ['provider'=>$provider,'methods'=>$methods];
    }

    public function payTip(int $spaceId, int $requestId, int $methodId, object $user): void
    {
        if(isset($_POST['loyalty_points']) || isset($_POST['rewards_points']) || isset($_POST['loyalty_reservation_token'])) throw new RuntimeException('Rewards cannot be used for tips.');
        $this->db->query("SELECT r.*,s.id_owner,s.id_order FROM event_execution_music_requests r JOIN event_execution_spaces s ON s.id=r.id_space WHERE r.id=:id AND r.id_space=:space AND r.id_user=:user AND r.request_type='SONG_REQUEST' LIMIT 1");
        $this->db->bind(':id',$requestId);$this->db->bind(':space',$spaceId);$this->db->bind(':user',(int)$user->getId());$request=$this->db->fetchOne();
        if(!$request || (float)$request->tip_amount<1 || $request->tip_status==='PAID') throw new RuntimeException('This tip cannot be paid.');
        $credentials=(new PaymentProvidersRepository())->getActiveProviderForOwner((int)$request->id_owner);
        if(!$credentials) throw new RuntimeException('No active payment provider is configured for this event.');
        $type=strtolower((string)$credentials->provider_type);
        $method=(new ClientPaymentMethodService())->getActiveMethodForClientProvider($methodId,(int)$request->id_owner,(int)$user->getId(),$type);
        if(!$method) throw new RuntimeException('Select a saved payment method for this business.');
        $provider=PaymentProviderFactory::create($credentials);
        if(!$provider->supportsChargingSavedPaymentMethods()) throw new RuntimeException('The active provider cannot charge saved methods.');
        $this->db->query("UPDATE event_execution_music_requests SET tip_status='PROCESSING' WHERE id=:id AND tip_status='PENDING'");$this->db->bind(':id',$requestId);$this->db->execute();
        if($this->db->rowCount()!==1) throw new RuntimeException('This tip is already being processed.');
        try {
            $charge=$provider->chargeSavedPaymentMethod($method,(float)$request->tip_amount,['source'=>'event_execution_song_request','order_id'=>(int)$request->id_order,'music_request_id'=>$requestId,'customer_email'=>$user->getEmail()]);
        } catch (\Throwable $e) {
            $this->db->query("UPDATE event_execution_music_requests SET tip_status='PENDING' WHERE id=:id AND tip_status='PROCESSING'");$this->db->bind(':id',$requestId);$this->db->execute();throw $e;
        }
        if(!$charge || empty($charge->id)) {$this->db->query("UPDATE event_execution_music_requests SET tip_status='PENDING' WHERE id=:id AND tip_status='PROCESSING'");$this->db->bind(':id',$requestId);$this->db->execute();throw new RuntimeException('The tip payment was not approved.');}
        $this->db->beginTransaction();
        try {
            $this->db->query("INSERT INTO event_execution_tip_payments (id_space,id_music_request,id_user,id_owner,tip_type,provider_type,provider_payment_id,amount,currency,status,saved_payment_method_id,metadata_json) VALUES (:space,:request,:user,:owner,'DJ_REQUEST',:provider,:payment,:amount,:currency,'PAID',:method,:metadata)");
            foreach(['space'=>$spaceId,'request'=>$requestId,'user'=>(int)$user->getId(),'owner'=>(int)$request->id_owner,'provider'=>$type,'payment'=>(string)$charge->id,'amount'=>(float)$request->tip_amount,'currency'=>strtoupper((string)($credentials->currency??'USD')),'method'=>$methodId,'metadata'=>json_encode(['saved_payment_method_id'=>$methodId,'rewards_used'=>false])] as $k=>$v)$this->db->bind(':'.$k,$v);
            $this->db->execute();
            $this->db->query("UPDATE event_execution_music_requests SET tip_status='PAID',tip_transaction_id=:payment WHERE id=:id AND tip_status='PROCESSING'");
            $this->db->bind(':payment',(string)$charge->id);$this->db->bind(':id',$requestId);$this->db->execute();$this->db->commit();
        } catch (\Throwable $e) {$this->db->rollback();throw $e;}
    }

    public function payTeamTip(int $spaceId,int $recipientUserId,float $amount,int $methodId,string $note,object $user): void
    {
        if(isset($_POST['loyalty_points']) || isset($_POST['rewards_points']) || isset($_POST['loyalty_reservation_token'])) throw new RuntimeException('Rewards cannot be used for tips.');
        if($amount<1 || $amount>1000)throw new RuntimeException('Enter a tip between $1 and $1,000.');
        $this->db->query("SELECT s.id_owner,s.id_order,m.role FROM event_execution_spaces s JOIN event_execution_members m ON m.id_space=s.id AND m.id_user=:recipient WHERE s.id=:space AND m.role IN ('TEAM','DJ') AND EXISTS(SELECT 1 FROM orders_staff_invites i WHERE i.id_order=s.id_order AND i.id_user=m.id_user AND i.is_confirmed=1) LIMIT 1");$this->db->bind(':recipient',$recipientUserId);$this->db->bind(':space',$spaceId);$target=$this->db->fetchOne();if(!$target)throw new RuntimeException('That team member is not assigned to this event.');
        $credentials=(new PaymentProvidersRepository())->getActiveProviderForOwner((int)$target->id_owner);if(!$credentials)throw new RuntimeException('No active payment provider is configured.');$type=strtolower((string)$credentials->provider_type);
        $method=(new ClientPaymentMethodService())->getActiveMethodForClientProvider($methodId,(int)$target->id_owner,(int)$user->getId(),$type);if(!$method)throw new RuntimeException('Select a valid saved payment method.');$provider=PaymentProviderFactory::create($credentials);if(!$provider->supportsChargingSavedPaymentMethods())throw new RuntimeException('The active provider cannot charge saved methods.');
        $charge=$provider->chargeSavedPaymentMethod($method,$amount,['source'=>'event_execution_team_tip','order_id'=>(int)$target->id_order,'recipient_user_id'=>$recipientUserId,'customer_email'=>$user->getEmail()]);if(!$charge||empty($charge->id))throw new RuntimeException('The tip payment was not approved.');
        $this->db->query("INSERT INTO event_execution_tip_payments (id_space,id_music_request,id_user,recipient_user_id,id_owner,tip_type,provider_type,provider_payment_id,amount,sender_note,saved_payment_method_id,currency,status,metadata_json) VALUES (:space,NULL,:sender,:recipient,:owner,'TEAM_MEMBER',:provider,:payment,:amount,:note,:method,:currency,'PAID',:metadata)");
        foreach(['space'=>$spaceId,'sender'=>(int)$user->getId(),'recipient'=>$recipientUserId,'owner'=>(int)$target->id_owner,'provider'=>$type,'payment'=>(string)$charge->id,'amount'=>$amount,'note'=>mb_substr(trim($note),0,500),'method'=>$methodId,'currency'=>strtoupper((string)($credentials->currency??'USD')),'metadata'=>json_encode(['rewards_used'=>false])] as $key=>$value)$this->db->bind(':'.$key,$value);$this->db->execute();
        $this->notify($recipientUserId,'You received a $'.number_format($amount,2).' event tip.','/panel/event-execution');
    }

    public function addPhoto(int $spaceId, object $user, array $file, string $caption=''): void
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size']??0)>10485760) throw new RuntimeException('Choose an image up to 10 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new RuntimeException('Only JPG, PNG and WEBP images are allowed.');
        $this->db->query('SELECT s.max_guest_photos,o.id_client FROM event_execution_spaces s JOIN orders o ON o.id=s.id_order WHERE s.id=:space LIMIT 1');$this->db->bind(':space',$spaceId);$space=$this->db->fetchOne();
        if(!$space) throw new RuntimeException('Event area not found.');
        $limit=max(1,(int)($space->max_guest_photos??self::DEFAULT_PHOTO_LIMIT));
        $this->db->query('SELECT COUNT(*) total FROM event_execution_photos WHERE id_space=:space AND id_user=:user AND deleted_at IS NULL');
        $this->db->bind(':space',$spaceId); $this->db->bind(':user',(int)$user->getId());
        $photoCount=(int)($this->db->fetchOne()->total??0);
        if((int)$user->getLevel()!==1 && (int)$user->getId()!==(int)$space->id_client && $photoCount>=$limit) throw new RuntimeException('You already reached the maximum of '.$limit.' photos for this event.');
        $url=FileUtils::saveFile($file,'vnv-events/event-execution/'.$spaceId);
        $this->db->query('INSERT INTO event_execution_photos (id_space,id_user,photo_url,caption,expires_at) VALUES (:space,:user,:url,:caption,NULL)');
        $this->db->bind(':space',$spaceId);$this->db->bind(':user',(int)$user->getId());$this->db->bind(':url',$url);$this->db->bind(':caption',mb_substr(trim($caption),0,240));$this->db->execute();
    }

    public function deletePhoto(int $spaceId, int $photoId, object $user, bool $clientOwner): void
    {
        $all=$clientOwner || (int)$user->getLevel()===1;
        $where='id=:id AND id_space=:space AND deleted_at IS NULL'.($all?'':' AND id_user=:user');
        $this->db->query('SELECT photo_url FROM event_execution_photos WHERE '.$where.' LIMIT 1');$this->db->bind(':id',$photoId);$this->db->bind(':space',$spaceId);if(!$all)$this->db->bind(':user',(int)$user->getId());$photo=$this->db->fetchOne();
        if(!$photo) throw new RuntimeException('Photo not found or cannot be deleted by this user.');
        $sql='UPDATE event_execution_photos SET deleted_at=NOW(),deleted_by=:actor WHERE '.$where;
        $this->db->query($sql);$this->db->bind(':actor',(int)$user->getId());$this->db->bind(':id',$photoId);$this->db->bind(':space',$spaceId);if(!$all)$this->db->bind(':user',(int)$user->getId());$this->db->execute();
    }

    public function deleteAllPhotos(int $spaceId, object $user, bool $clientOwner): void
    {
        if(!$clientOwner && (int)$user->getLevel()!==1) throw new RuntimeException('Only the event client or administrator can clear the gallery.');
        $this->db->query('SELECT photo_url FROM event_execution_photos WHERE id_space=:space AND deleted_at IS NULL');$this->db->bind(':space',$spaceId);$photos=$this->db->fetchAll();
        $this->db->query('UPDATE event_execution_photos SET deleted_at=NOW(),deleted_by=:user WHERE id_space=:space AND deleted_at IS NULL');$this->db->bind(':user',(int)$user->getId());$this->db->bind(':space',$spaceId);$this->db->execute();
    }

    public function purgeExpiredPhotos(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $this->db->query("SELECT id, photo_url FROM event_execution_photos WHERE deleted_at IS NULL AND expires_at<=NOW() ORDER BY expires_at LIMIT {$limit}");
        $photos = $this->db->fetchAll();
        $purged = 0;
        foreach ($photos as $photo) {
            FileUtils::removeFile((string)$photo->photo_url);
            $this->db->query('UPDATE event_execution_photos SET deleted_at=NOW() WHERE id=:id AND deleted_at IS NULL');
            $this->db->bind(':id', (int)$photo->id);
            $this->db->execute();
            $purged++;
        }
        return $purged;
    }

    private function validatedYoutubeUrl(string $url): ?string
    {
        $url=trim($url);if($url==='')return null;
        if(!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Enter a valid YouTube URL.');
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));$host=preg_replace('/^www\./','',$host);
        if(!in_array($host,['youtube.com','m.youtube.com','youtu.be'],true))throw new RuntimeException('Only YouTube links are accepted.');
        return mb_substr($url,0,700);
    }

    private function notify(int $userId,string $message,string $link): void
    {
        if($userId<=0)return;
        $this->db->query("INSERT INTO notifications (id_user,mensaje,link,leido,timestamp) VALUES (:user,:message,:link,'NO',NOW())");
        $this->db->bind(':user',$userId);$this->db->bind(':message',mb_substr($message,0,120));$this->db->bind(':link',mb_substr($link,0,250));$this->db->execute();
        try { NotificationService::sendToUsers([$userId],'VNV Events',$message,['url'=>$link]); } catch (\Throwable) {}
    }
}
