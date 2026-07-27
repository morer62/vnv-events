<?php

namespace App\Services;

use RuntimeException;

final class AiVideoTranscriptionService
{
    private const CHUNK_SECONDS = 1200;
    private const MAX_CHUNK_BYTES = 24 * 1024 * 1024;

    public function transcribe(string $url): array
    {
        $apiKey=trim((string)($_ENV['OPENAI_TOKEN']??''));
        if($apiKey==='')throw new RuntimeException('OPENAI_TOKEN is not configured.');
        $work=sys_get_temp_dir().DIRECTORY_SEPARATOR.'vnv-transcribe-'.bin2hex(random_bytes(8));
        if(!mkdir($work,0700,true)&&!is_dir($work))throw new RuntimeException('Could not create the transcription workspace.');
        try{
            $source=(new AiVideoIngestService())->materialize($url,$work.DIRECTORY_SEPARATOR.'source-media');
            $pattern=$work.DIRECTORY_SEPARATOR.'audio-%05d.mp3';
            $this->execute([$this->binary(),'-y','-i',$source,'-vn','-ac','1','-ar','16000','-c:a','libmp3lame','-b:a','48k','-f','segment','-segment_time',(string)self::CHUNK_SECONDS,'-reset_timestamps','1',$pattern]);
            $chunks=glob($work.DIRECTORY_SEPARATOR.'audio-*.mp3')?:[];
            sort($chunks,SORT_NATURAL);
            if(!$chunks)throw new RuntimeException('FFmpeg could not extract an audio track from this media.');
            $text=[];$segments=[];$words=[];$rawChunks=[];
            foreach($chunks as $index=>$chunk){
                if(filesize($chunk)>self::MAX_CHUNK_BYTES)throw new RuntimeException('A transcription audio segment exceeds the provider limit.');
                $result=$this->request($chunk,$apiKey);$offset=$index*self::CHUNK_SECONDS;
                $chunkText=trim((string)($result['text']??''));if($chunkText!=='')$text[]=$chunkText;
                foreach((array)($result['segments']??[]) as $segment){
                    if(!is_array($segment))continue;
                    $segment['start']=(float)($segment['start']??0)+$offset;
                    $segment['end']=(float)($segment['end']??0)+$offset;
                    $segments[]=$segment;
                }
                foreach((array)($result['words']??[]) as $word){
                    if(!is_array($word))continue;
                    $word['start']=(float)($word['start']??0)+$offset;
                    $word['end']=(float)($word['end']??0)+$offset;
                    $words[]=$word;
                }
                $rawChunks[]=['index'=>$index,'offset_seconds'=>$offset,'result'=>$result];
            }
            return ['text'=>implode("\n\n",$text),'raw'=>['chunk_seconds'=>self::CHUNK_SECONDS,'chunks'=>$rawChunks,'segments'=>$segments,'words'=>$words],'srt'=>$this->srt($segments)];
        }finally{
            foreach(glob($work.DIRECTORY_SEPARATOR.'*')?:[] as $file)if(is_file($file))@unlink($file);
            @rmdir($work);
        }
    }

    private function request(string $file,string $apiKey): array
    {
        $ch=curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>900,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],
            CURLOPT_POSTFIELDS=>[
                'file'=>new \CURLFile($file,'audio/mpeg',basename($file)),
                'model'=>(string)($_ENV['OPENAI_TRANSCRIPTION_MODEL']??'whisper-1'),
                'response_format'=>'verbose_json','timestamp_granularities[0]'=>'segment','timestamp_granularities[1]'=>'word',
            ],
        ]);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if(!is_string($response)||$status<200||$status>=300)throw new RuntimeException('Transcription failed: '.($error?:mb_substr((string)$response,0,500)));
        $json=json_decode($response,true);
        if(!is_array($json))throw new RuntimeException('Transcription returned invalid JSON.');
        return $json;
    }

    private function binary(): string
    {
        $configured=trim((string)($_ENV['FFMPEG_PATH']??''));
        if($configured!==''&&is_file($configured))return $configured;
        if(PHP_OS_FAMILY==='Windows'){
            $matches=glob((getenv('LOCALAPPDATA')?:'').'/Microsoft/WinGet/Packages/Gyan.FFmpeg_*/ffmpeg-*/bin/ffmpeg.exe');
            if($matches)return (string)end($matches);
        }
        $process=proc_open(['ffmpeg','-version'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(is_resource($process)){foreach($pipes as $pipe)fclose($pipe);if(proc_close($process)===0)return 'ffmpeg';}
        throw new RuntimeException('FFmpeg is unavailable. Configure FFMPEG_PATH with the absolute binary path.');
    }

    private function execute(array $command): void
    {
        $process=proc_open($command,[1=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Unable to start FFmpeg for transcription.');
        $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
        if($code!==0)throw new RuntimeException('Audio preparation failed: '.mb_substr(trim($stderr),-1500));
    }

    private function srt(array $segments): string
    {
        $lines=[];$index=1;
        foreach($segments as $segment){$value=trim((string)($segment['text']??''));if($value==='')continue;$lines[]=(string)$index++;$lines[]=$this->time((float)($segment['start']??0)).' --> '.$this->time((float)($segment['end']??0));$lines[]=$value;$lines[]='';}
        return implode("\n",$lines);
    }

    private function time(float $seconds): string
    {
        $ms=(int)round($seconds*1000);$hours=intdiv($ms,3600000);$ms%=3600000;$minutes=intdiv($ms,60000);$ms%=60000;
        return sprintf('%02d:%02d:%02d,%03d',$hours,$minutes,intdiv($ms,1000),$ms%1000);
    }
}
