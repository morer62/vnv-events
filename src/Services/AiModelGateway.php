<?php
namespace App\Services;

use App\Repositories\AiProviderConnectionsRepository;
use App\Repositories\Connection;
use RuntimeException;

final class AiModelGateway
{
    public function json(int $ownerId,string $provider,string $system,array $context,array $shape): array
    {
        $repo=new AiProviderConnectionsRepository();$provider=$provider?:$repo->defaultProvider($ownerId);$credentials=$repo->credentials($ownerId,$provider);
        if(!$credentials&&$provider==='openai')$credentials=['api_key'=>(string)($_ENV['OPENAI_TOKEN']??''),'text_model'=>(string)($_ENV['OPENAI_TEXT_MODEL']??'gpt-4o-mini')];
        if(!$credentials||trim($credentials['api_key'])==='')throw new RuntimeException(ucfirst($provider).' credentials are not configured.');
        $prompt="Return valid JSON only matching this shape:\n".json_encode($shape)."\nVerified context:\n".json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $response=match($provider){'anthropic'=>$this->anthropic($credentials,$system,$prompt),'gemini'=>$this->gemini($credentials,$system,$prompt),default=>$this->openai($credentials,$system,$prompt)};
        $text=$response['text'];$this->logUsage($ownerId,$provider,$response['model'],$response['input_tokens'],$response['output_tokens']);
        $text=preg_replace('/^```(?:json)?\s*|\s*```$/i','',trim($text));$result=json_decode($text,true);if(!is_array($result))throw new RuntimeException(ucfirst($provider).' returned invalid JSON.');return $result;
    }
    private function request(string $url,array $headers,array $payload): array{$body=false;$error='';$status=0;for($attempt=1;$attempt<=3;$attempt++){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(!in_array($status,[429,500,502,503,504],true)||$attempt===3)break;usleep((2**$attempt)*250000);}if($body===false||$status<200||$status>=300){$decoded=json_decode((string)$body,true);$detail=$decoded['error']['message']??$decoded['message']??$error;throw new RuntimeException('AI provider request failed'.($detail?': '.mb_substr((string)$detail,0,500):'.'));}return json_decode((string)$body,true)?:[];}
    private function openai(array $c,string $system,string $prompt): array{$model=$c['text_model']?:'gpt-4o-mini';$r=$this->request('https://api.openai.com/v1/chat/completions',['Authorization: Bearer '.$c['api_key'],'Content-Type: application/json'],['model'=>$model,'response_format'=>['type'=>'json_object'],'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]]]);return ['text'=>(string)($r['choices'][0]['message']['content']??''),'model'=>$model,'input_tokens'=>(int)($r['usage']['prompt_tokens']??0),'output_tokens'=>(int)($r['usage']['completion_tokens']??0)];}
    private function anthropic(array $c,string $system,string $prompt): array{$model=$c['text_model']?:'claude-sonnet-4-6';$r=$this->request('https://api.anthropic.com/v1/messages',['x-api-key: '.$c['api_key'],'anthropic-version: 2023-06-01','Content-Type: application/json'],['model'=>$model,'max_tokens'=>8192,'system'=>$system,'messages'=>[['role'=>'user','content'=>$prompt]]]);return ['text'=>(string)($r['content'][0]['text']??''),'model'=>$model,'input_tokens'=>(int)($r['usage']['input_tokens']??0),'output_tokens'=>(int)($r['usage']['output_tokens']??0)];}
    private function gemini(array $c,string $system,string $prompt): array{$model=$c['text_model']?:'gemini-2.5-flash';$r=$this->request('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent',['x-goog-api-key: '.$c['api_key'],'Content-Type: application/json'],['system_instruction'=>['parts'=>[['text'=>$system]]],'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']]);return ['text'=>(string)($r['candidates'][0]['content']['parts'][0]['text']??''),'model'=>$model,'input_tokens'=>(int)($r['usageMetadata']['promptTokenCount']??0),'output_tokens'=>(int)($r['usageMetadata']['candidatesTokenCount']??0)];}
    private function logUsage(int $owner,string $provider,string $model,int $input,int $output): void
    {
        try{$db=new Connection();$db->query("INSERT INTO ai_agent_usage_logs(id_owner,provider,model,operation,input_tokens,output_tokens) VALUES(:owner,:provider,:model,'json_generation',:input,:output)");
            $db->bind(':owner',$owner);$db->bind(':provider',$provider);$db->bind(':model',$model);$db->bind(':input',$input);$db->bind(':output',$output);$db->execute();}catch(\Throwable){}
    }
}
