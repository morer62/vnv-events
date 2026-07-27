<?php

namespace App\Services;

use RuntimeException;

final class AiJsonGenerator
{
    public function generate(string $system, array $context, array $shape): array
    {
        $key=trim((string)($_ENV['OPENAI_TOKEN']??$_ENV['OPENAI_API_KEY']??''));
        if($key==='')throw new RuntimeException('OPENAI_TOKEN is not configured.');
        $payload=['model'=>trim((string)($_ENV['OPENAI_TEXT_MODEL']??'gpt-4o-mini')),'temperature'=>0.35,'response_format'=>['type'=>'json_object'],'messages'=>[
            ['role'=>'system','content'=>$system.' Return valid JSON only. Never invent facts, prices, availability, addresses, reviews or promises.'],
            ['role'=>'user','content'=>"Required JSON shape:\n".json_encode($shape,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\nVerified context:\n".json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
        ]];
        $ch=curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false||$status<200||$status>=300)throw new RuntimeException('OpenAI generation failed'.($error?': '.$error:'.'));
        $response=json_decode((string)$body,true);$result=json_decode((string)($response['choices'][0]['message']['content']??''),true);
        if(!is_array($result))throw new RuntimeException('OpenAI returned invalid JSON.');
        return $result;
    }
}
