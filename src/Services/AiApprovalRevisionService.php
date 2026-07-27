<?php

namespace App\Services;

use RuntimeException;

final class AiApprovalRevisionService
{
    public function revise(string $agentName, string $actionType, array $payload, string $instructions): array
    {
        if (trim($instructions) === '') throw new RuntimeException('Enter the corrections you want the agent to make.');
        $apiKey=trim((string)($_ENV['OPENAI_TOKEN']??$_ENV['OPENAI_API_KEY']??''));
        if($apiKey==='') throw new RuntimeException('OPENAI_TOKEN is required to process corrections.');
        $request=[
            'model'=>trim((string)($_ENV['OPENAI_TEXT_MODEL']??'gpt-4o-mini')),
            'temperature'=>0.25,
            'response_format'=>['type'=>'json_object'],
            'messages'=>[
                ['role'=>'system','content'=>"You revise VNV Events approval drafts for the {$agentName} agent. Preserve all identifiers, URLs, order IDs, content IDs and factual data. Change only what the reviewer requests. Return the complete revised payload as valid JSON, with no commentary."],
                ['role'=>'user','content'=>"Action type: {$actionType}\nReviewer corrections:\n{$instructions}\n\nCurrent payload:\n".json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
            ],
        ];
        $ch=curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false||$status<200||$status>=300) throw new RuntimeException('The agent could not process the corrections'.($error?': '.$error:'.'));
        $response=json_decode((string)$body,true);$revised=json_decode((string)($response['choices'][0]['message']['content']??''),true);
        if(!is_array($revised)) throw new RuntimeException('The agent returned an invalid revised draft.');
        foreach(['order_id','content_id','lead_id','media_job_id','client_email','platform','image_url','output_url'] as $protected) if(array_key_exists($protected,$payload)) $revised[$protected]=$payload[$protected];
        return $revised;
    }
}
