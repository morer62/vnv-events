<?php
namespace App\Services;

use App\Repositories\AiProviderConnectionsRepository;
use App\Repositories\Connection;
use App\Utils\FileUtils;
use RuntimeException;

final class AiProviderImageService
{
    public function generate(int $ownerId,string $provider,string $prompt,string $aspectRatio='16:9'): array
    {
        if(!in_array($provider,['openai','gemini'],true))throw new RuntimeException('Image generation is available through OpenAI or Gemini.');
        $repo=new AiProviderConnectionsRepository();$c=$repo->credentials($ownerId,$provider);
        if(!$c&&$provider==='openai')$c=['api_key'=>(string)($_ENV['OPENAI_TOKEN']??''),'image_model'=>(string)($_ENV['OPENAI_IMAGE_MODEL']??'gpt-image-1')];
        if(!$c||trim($c['api_key'])==='')throw new RuntimeException(ucfirst($provider).' image credentials are not configured.');
        [$binary,$mime]=$provider==='gemini'?$this->gemini($c,$prompt,$aspectRatio):$this->openai($c,$prompt,$aspectRatio);
        $extension=str_contains($mime,'jpeg')?'jpg':'png';$url=FileUtils::saveFileFromContent($binary,'agents/blog-optimizer/images',$extension);
        try{$db=new Connection();$db->query("INSERT INTO ai_agent_usage_logs(id_owner,provider,model,operation,metadata_json) VALUES(:owner,:provider,:model,'image_generation',:metadata)");
            $db->bind(':owner',$ownerId);$db->bind(':provider',$provider);$db->bind(':model',(string)$c['image_model']);$db->bind(':metadata',json_encode(['aspect_ratio'=>$aspectRatio,'images'=>1]));$db->execute();}catch(\Throwable){}
        return ['url'=>$url,'provider'=>$provider,'model'=>$c['image_model'],'prompt'=>$prompt,'aspect_ratio'=>$aspectRatio];
    }
    private function openai(array $c,string $prompt,string $ratio): array
    {
        $size=$ratio==='16:9'?'1536x1024':($ratio==='9:16'?'1024x1536':'1024x1024');$r=$this->request('https://api.openai.com/v1/images/generations',['Authorization: Bearer '.$c['api_key'],'Content-Type: application/json'],['model'=>$c['image_model']?:'gpt-image-1','prompt'=>$prompt,'size'=>$size,'n'=>1]);$item=$r['data'][0]??[];$binary=!empty($item['b64_json'])?base64_decode($item['b64_json'],true):$this->download((string)($item['url']??''));if(!$binary)throw new RuntimeException('OpenAI returned no image.');return [$binary,'image/png'];
    }
    private function gemini(array $c,string $prompt,string $ratio): array
    {
        $r=$this->request('https://generativelanguage.googleapis.com/v1beta/interactions',['x-goog-api-key: '.$c['api_key'],'Content-Type: application/json'],['model'=>$c['image_model']?:'gemini-3.1-flash-image','input'=>$prompt,'response_format'=>['type'=>'image','mime_type'=>'image/png','aspect_ratio'=>$ratio]]);
        $found=$this->findImage($r);if(!$found)throw new RuntimeException('Gemini returned no image.');return [base64_decode($found['data'],true),(string)($found['mime_type']??'image/png')];
    }
    private function findImage(array $node): ?array{if(isset($node['data'])&&is_string($node['data'])&&str_starts_with((string)($node['mime_type']??''),'image/'))return $node;foreach($node as $value)if(is_array($value)){if($found=$this->findImage($value))return $found;}return null;}
    private function request(string $url,array $headers,array $payload): array{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>240,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($body===false||$status<200||$status>=300)throw new RuntimeException('Image provider request failed'.($error?': '.$error:'.'));return json_decode((string)$body,true)?:[];}
    private function download(string $url): string{if($url==='')return '';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>120]);$body=curl_exec($ch);curl_close($ch);return is_string($body)?$body:'';}
}
