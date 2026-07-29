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
            $silences=$this->detectSilences($source);
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
            if(!$segments&&$words)$segments=$this->segmentsFromWords($words);
            [$segments,$words]=$this->applySilences($segments,$words,$silences);
            return ['text'=>implode("\n\n",$text),'raw'=>['chunk_seconds'=>self::CHUNK_SECONDS,'chunks'=>$rawChunks,'segments'=>$segments,'words'=>$words,'silences'=>$silences],'srt'=>$this->srt($segments)];
        }finally{
            foreach(glob($work.DIRECTORY_SEPARATOR.'*')?:[] as $file)if(is_file($file))@unlink($file);
            @rmdir($work);
        }
    }

    public function improveTiming(string $url,string $transcriptJson,string $fallbackText=''): array
    {
        $raw=json_decode($transcriptJson,true);if(!is_array($raw))$raw=[];
        $source=(new AiVideoIngestService())->localPath($url);
        if(!$source)throw new RuntimeException('Silence analysis requires a private SFTP source.');
        $silences=$this->detectSilences($source);
        $segments=(array)($raw['segments']??[]);$words=(array)($raw['words']??[]);
        if(!$segments&&$words)$segments=$this->segmentsFromWords($words);
        [$segments,$words]=$this->applySilences($segments,$words,$silences);
        $raw['segments']=$segments;$raw['words']=$words;$raw['silences']=$silences;$raw['timing_analysis']='ffmpeg_silencedetect';
        $text=trim($fallbackText);if($text==='')$text=implode("\n\n",array_values(array_filter(array_map(fn($segment)=>trim((string)($segment['text']??'')),$segments))));
        return ['text'=>$text,'raw'=>$raw,'srt'=>$this->srt($segments)];
    }

    private function detectSilences(string $source): array
    {
        $command=[$this->binary(),'-hide_banner','-nostats','-i',$source,'-vn','-af','silencedetect=noise=-38dB:d=0.55','-f','null',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null'];
        $process=proc_open($command,[1=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Unable to analyze the audio timing.');
        $log=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
        if($code!==0)throw new RuntimeException('Silence analysis failed: '.mb_substr(trim($log),-900));
        preg_match_all('/silence_start:\s*([0-9.]+)/',$log,$starts);preg_match_all('/silence_end:\s*([0-9.]+)/',$log,$ends);
        $silences=[];foreach($starts[1]??[] as $index=>$start){if(!isset($ends[1][$index]))continue;$a=(float)$start;$b=(float)$ends[1][$index];if($b-$a>=.55)$silences[]=['start'=>$a,'end'=>$b,'duration'=>$b-$a];}
        return $silences;
    }

    private function applySilences(array $segments,array $words,array $silences): array
    {
        $adjust=function(array $item)use($silences):array{
            $start=(float)($item['start']??0);$end=max($start,(float)($item['end']??$start));
            foreach($silences as $silence){
                $a=(float)$silence['start'];$b=(float)$silence['end'];
                if($start>=$a-.08&&$start<$b&&$b<$end-.05)$start=$b;
                if($end>$a&&$end<=$b+.08&&$a>$start+.05)$end=$a;
            }
            $text=trim((string)($item['text']??$item['word']??''));
            if($text!==''){
                $wordCount=max(1,count(preg_split('/\s+/u',$text)?:[]));$minimumVoice=$wordCount/4.2;$candidate=$start;
                foreach($silences as $silence){
                    $a=(float)$silence['start'];$b=(float)$silence['end'];if($b-$a<1.5||$a<=$start||$b>=$end)continue;
                    $voiced=max(0,$end-$b);
                    foreach($silences as $later){$laterStart=max($b,(float)$later['start']);$laterEnd=min($end,(float)$later['end']);if($laterEnd>$laterStart)$voiced-=$laterEnd-$laterStart;}
                    if($voiced>=$minimumVoice)$candidate=max($candidate,$b);
                }
                $start=$candidate;
            }
            $item['start']=$start;$item['end']=max($start+.01,$end);return $item;
        };
        return [array_map($adjust,array_values(array_filter($segments,'is_array'))),array_map($adjust,array_values(array_filter($words,'is_array')))];
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
                // Requesting the word granularity also returns segments in verbose_json.
                // Sending indexed multipart keys was silently ignored by the API and left
                // captions estimated across entire phrases.
                'response_format'=>'verbose_json','timestamp_granularities[]'=>'word',
            ],
        ]);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if(!is_string($response)||$status<200||$status>=300)throw new RuntimeException('Transcription failed: '.($error?:mb_substr((string)$response,0,500)));
        $json=json_decode($response,true);
        if(!is_array($json))throw new RuntimeException('Transcription returned invalid JSON.');
        return $json;
    }

    private function segmentsFromWords(array $words): array
    {
        $segments=[];$current=[];$start=0.0;$lastEnd=0.0;
        $flush=function()use(&$segments,&$current,&$start,&$lastEnd):void{
            if(!$current)return;
            $text='';foreach($current as $word){$value=trim((string)($word['word']??$word['text']??''));if($value==='')continue;$text.=($text!==''&&!preg_match('/^[,.;:!?]/u',$value)?' ':'').$value;}
            if($text!=='')$segments[]=['start'=>$start,'end'=>$lastEnd,'text'=>$text];
            $current=[];
        };
        foreach($words as $word){
            if(!is_array($word))continue;
            $wordStart=max(0,(float)($word['start']??0));$wordEnd=max($wordStart+.01,(float)($word['end']??$wordStart));
            if($current&&($wordStart-$lastEnd>.55||$wordEnd-$start>4.2||count($current)>=10))$flush();
            if(!$current)$start=$wordStart;
            $current[]=$word;$lastEnd=$wordEnd;
            if(preg_match('/[.!?]["\']?$/u',trim((string)($word['word']??$word['text']??''))))$flush();
        }
        $flush();
        return $segments;
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
