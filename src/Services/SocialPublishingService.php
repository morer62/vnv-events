<?php
namespace App\Services;

use App\Repositories\AiAgentConnectionsRepository;
use App\Repositories\AiAgentsRepository;
use RuntimeException;

final class SocialPublishingService
{
    public function __construct(private ?AiAgentConnectionsRepository $connections=null)
    {
        $this->connections??=new AiAgentConnectionsRepository();
    }

    public function verify(int $ownerId,string $platform): array
    {
        $agent=(new AiAgentsRepository())->find($ownerId,'social_publisher');
        if(!$agent)throw new RuntimeException('Social Publisher agent is not initialized.');
        $c=$this->connections->credentials($ownerId,(int)$agent->id,$platform);
        try{
            $graph=$this->graphBase();
            $result=match($platform){
                'facebook'=>$this->request('GET',$graph.'/'.rawurlencode($c['account_identifier']).'?fields=id,name&access_token='.rawurlencode($c['access_token'])),
                'instagram'=>$this->request('GET',$graph.'/'.rawurlencode($c['account_identifier']).'?fields=id,username&access_token='.rawurlencode($c['access_token'])),
                'linkedin'=>$this->verifyLinkedin($c),
                'youtube'=>$this->request('GET','https://www.googleapis.com/youtube/v3/channels?part=id,snippet&mine=true',null,['Authorization: Bearer '.$c['access_token']]),
                'whatsapp'=>$this->request('GET',$graph.'/'.rawurlencode($c['account_identifier']).'?fields=id,display_phone_number,verified_name&access_token='.rawurlencode($c['access_token'])),
                default=>throw new RuntimeException('Unsupported social platform.'),
            };
            $this->connections->verificationResult($c['connection_id'],true);
            return $result;
        }catch(\Throwable $e){$this->connections->verificationResult($c['connection_id'],false,$e->getMessage());throw $e;}
    }

    public function publish(int $ownerId,string $platform,array $payload): array
    {
        $agent=(new AiAgentsRepository())->find($ownerId,'social_publisher');
        if(!$agent)throw new RuntimeException('Social Publisher agent is not initialized.');
        $c=$this->connections->credentials($ownerId,(int)$agent->id,$platform);
        $copy=trim((string)($payload['copy']??$payload['caption']??''));
        $tags=array_map(fn($v)=>'#'.ltrim((string)$v,'#'),(array)($payload['hashtags']??[]));
        $message=trim($copy."\n\n".implode(' ',$tags));
        if($message==='')throw new RuntimeException('The approved post is empty.');
        return match($platform){
            'facebook'=>$this->publishFacebook($c,$payload,$message),
            'linkedin'=>$this->publishLinkedin($c,$payload,$message),
            'instagram'=>$this->publishInstagram($c,$payload,$message),
            'youtube'=>$this->publishYoutube($c,$payload,$message),
            default=>throw new RuntimeException('Unsupported social platform.'),
        };
    }
    private function publishLinkedin(array $c,array $payload,string $message): array
    {
        $author=str_starts_with($c['account_identifier'],'urn:')?$c['account_identifier']:'urn:li:organization:'.$c['account_identifier'];
        $post=[
                'author'=>$author,
                'commentary'=>$message,'visibility'=>'PUBLIC',
                'distribution'=>['feedDistribution'=>'MAIN_FEED','targetEntities'=>[],'thirdPartyDistributionChannels'=>[]],
                'lifecycleState'=>'PUBLISHED','isReshareDisabledByAuthor'=>false,
        ];
        $video=(string)($payload['video_url']??'');if(filter_var($video,FILTER_VALIDATE_URL))$post['content']=['media'=>['id'=>$this->uploadLinkedinVideo($c,$author,$video),'title'=>(string)($payload['youtube_title']??$payload['title']??'VNV Events')]];
        return $this->request('POST','https://api.linkedin.com/rest/posts',$post,$this->linkedinHeaders($c));
    }
    private function uploadLinkedinVideo(array $c,string $owner,string $url): string
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>600]);$binary=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(!is_string($binary)||$status<200||$status>=300)throw new RuntimeException('Unable to download video for LinkedIn.');
        $init=$this->request('POST','https://api.linkedin.com/rest/videos?action=initializeUpload',['initializeUploadRequest'=>['owner'=>$owner,'fileSizeBytes'=>strlen($binary),'uploadCaptions'=>false,'uploadThumbnail'=>false]],$this->linkedinHeaders($c));
        $value=$init['value']??[];$parts=[];foreach((array)($value['uploadInstructions']??[]) as $instruction){$first=(int)($instruction['firstByte']??0);$last=(int)($instruction['lastByte']??strlen($binary)-1);$chunk=substr($binary,$first,$last-$first+1);$etag='';
            $ch=curl_init((string)$instruction['uploadUrl']);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>600,CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream'],CURLOPT_POSTFIELDS=>$chunk,CURLOPT_HEADERFUNCTION=>function($ch,$header)use(&$etag){if(str_starts_with(strtolower($header),'etag:'))$etag=trim(substr($header,5)," \t\r\n\"");return strlen($header);}]);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($code<200||$code>=300)throw new RuntimeException('LinkedIn video part upload failed.');if($etag!=='')$parts[]=$etag;
        }
        $video=(string)($value['video']??'');$this->request('POST','https://api.linkedin.com/rest/videos?action=finalizeUpload',['finalizeUploadRequest'=>['video'=>$video,'uploadToken'=>(string)($value['uploadToken']??''),'uploadedPartIds'=>$parts]],$this->linkedinHeaders($c));
        if($video==='')throw new RuntimeException('LinkedIn did not return a video URN.');return $video;
    }

    private function publishInstagram(array $c,array $payload,string $caption): array
    {
        $video=(string)($payload['video_url']??'');$base=$this->graphBase().'/'.rawurlencode($c['account_identifier']);
        if(filter_var($video,FILTER_VALIDATE_URL)){$container=$this->request('POST',$base.'/media',['media_type'=>'REELS','video_url'=>$video,'caption'=>$caption,'share_to_feed'=>'true','access_token'=>$c['access_token']]);return $this->request('POST',$base.'/media_publish',['creation_id'=>$container['id'],'access_token'=>$c['access_token']]);}
        $urls=[];foreach((array)($payload['slides']??[]) as $slide){$url=(string)($slide['image_url']??'');if($url!=='')$urls[]=$url;}
        if(!$urls&&filter_var($payload['image_url']??'',FILTER_VALIDATE_URL))$urls[]=$payload['image_url'];
        if(!$urls)throw new RuntimeException('Instagram requires at least one public image URL.');
        if(count($urls)===1){
            $container=$this->request('POST',$base.'/media',['image_url'=>$urls[0],'caption'=>$caption,'access_token'=>$c['access_token']]);
        }else{
            $children=[];foreach(array_slice($urls,0,10) as $url){$child=$this->request('POST',$base.'/media',['image_url'=>$url,'is_carousel_item'=>'true','access_token'=>$c['access_token']]);$children[]=$child['id'];}
            $container=$this->request('POST',$base.'/media',['media_type'=>'CAROUSEL','children'=>implode(',',$children),'caption'=>$caption,'access_token'=>$c['access_token']]);
        }
        return $this->request('POST',$base.'/media_publish',['creation_id'=>$container['id'],'access_token'=>$c['access_token']]);
    }
    private function publishFacebook(array $c,array $payload,string $message): array
    {
        $base=$this->graphBase().'/'.rawurlencode($c['account_identifier']);
        $video=(string)($payload['video_url']??'');if(filter_var($video,FILTER_VALIDATE_URL))return $this->request('POST',$base.'/videos',['file_url'=>$video,'description'=>$message,'access_token'=>$c['access_token']]);
        $image=(string)($payload['image_url']??'');
        return filter_var($image,FILTER_VALIDATE_URL)
            ?$this->request('POST',$base.'/photos',['url'=>$image,'caption'=>$message,'access_token'=>$c['access_token']])
            :$this->request('POST',$base.'/feed',['message'=>$message,'access_token'=>$c['access_token']]);
    }
    private function publishYoutube(array $c,array $payload,string $message): array
    {
        $video=(string)($payload['video_url']??$payload['output_url']??'');if(!filter_var($video,FILTER_VALIDATE_URL))throw new RuntimeException('YouTube publishing requires a rendered video URL.');
        $ch=curl_init($video);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>600]);$binary=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(!is_string($binary)||$status<200||$status>=300)throw new RuntimeException('Unable to download the rendered video for YouTube.');
        $boundary='vnv'.bin2hex(random_bytes(12));$meta=['snippet'=>['title'=>mb_substr((string)($payload['youtube_title']??$payload['title']??'VNV Events video'),0,100),'description'=>$message,'categoryId'=>'22'],'status'=>['privacyStatus'=>(string)($payload['privacy_status']??'private')]];
        $body="--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta,JSON_UNESCAPED_SLASHES)."\r\n--{$boundary}\r\nContent-Type: video/mp4\r\n\r\n".$binary."\r\n--{$boundary}--";
        return $this->rawRequest('POST','https://www.googleapis.com/upload/youtube/v3/videos?uploadType=multipart&part=snippet,status',$body,['Authorization: Bearer '.$c['access_token'],'Content-Type: multipart/related; boundary='.$boundary]);
    }

    private function linkedinHeaders(array $c): array
    {
        return ['Authorization: Bearer '.$c['access_token'],'X-Restli-Protocol-Version: 2.0.0','Linkedin-Version: '.($_ENV['LINKEDIN_API_VERSION']??'202605')];
    }
    private function graphBase(): string{return 'https://graph.facebook.com/'.trim((string)($_ENV['META_GRAPH_VERSION']??'v23.0'),'/');}
    private function verifyLinkedin(array $c): array
    {
        $id=preg_replace('/^urn:li:organization:/','',(string)$c['account_identifier']);
        if(ctype_digit($id))return $this->request('GET','https://api.linkedin.com/rest/organizations/'.rawurlencode($id),null,$this->linkedinHeaders($c));
        return $this->request('GET','https://api.linkedin.com/v2/userinfo',null,$this->linkedinHeaders($c));
    }

    private function request(string $method,string $url,?array $body=null,array $headers=[]): array
    {
        $baseHeaders=$headers;$baseHeaders[]='Accept: application/json';$raw=false;$status=0;$error='';
        for($attempt=1;$attempt<=3;$attempt++){
            $headers=$baseHeaders;$ch=curl_init($url);
            if($body!==null){curl_setopt($ch,CURLOPT_POSTFIELDS,str_starts_with($url,'https://api.linkedin.com/')?json_encode($body,JSON_UNESCAPED_SLASHES):http_build_query($body));$headers[]='Content-Type: '.(str_starts_with($url,'https://api.linkedin.com/')?'application/json':'application/x-www-form-urlencoded');}
            curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>$headers]);
            $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
            if(!in_array($status,[429,500,502,503,504],true)||$attempt===3)break;usleep((2**$attempt)*250000);
        }
        if($raw===false)throw new RuntimeException('Social network connection failed: '.$error);
        $decoded=json_decode($raw,true);
        if($status<200||$status>=300){$detail=$decoded['error']['message']??$decoded['message']??substr($raw,0,500);throw new RuntimeException("Social API returned HTTP {$status}: {$detail}");}
        return is_array($decoded)?$decoded:['status'=>$status,'response'=>$raw];
    }
    private function rawRequest(string $method,string $url,string $body,array $headers): array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>900,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);$decoded=json_decode((string)$raw,true);
        if($raw===false||$status<200||$status>=300)throw new RuntimeException('YouTube API failed: '.($decoded['error']['message']??$error??'HTTP '.$status));return $decoded?:['status'=>$status];
    }
}
