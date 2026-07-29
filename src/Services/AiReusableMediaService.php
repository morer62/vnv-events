<?php

namespace App\Services;

use RuntimeException;

final class AiReusableMediaService
{
    public function validate(array $file,string $requestedType): array
    {
        $path=(string)($file['tmp_name']??'');
        if($path===''||!is_file($path))throw new RuntimeException('The reusable media upload could not be read.');
        if((int)($file['size']??0)>250*1024*1024)throw new RuntimeException('Reusable media must be below 250 MB.');
        $mime=(string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $imageMimes=['image/png','image/jpeg','image/webp'];
        $videoMimes=['video/mp4','video/quicktime','video/webm','video/x-m4v','video/x-matroska'];
        $audioMimes=['audio/mpeg','audio/wav','audio/x-wav','audio/mp4'];
        if(!in_array($mime,array_merge($imageMimes,$videoMimes,$audioMimes),true))throw new RuntimeException('Use PNG, JPG, WebP, MP4, MOV, WebM, MKV, MP3 or WAV reusable media.');
        $type=strtoupper(trim($requestedType));
        if(in_array($mime,$videoMimes,true)){
            $duration=$this->duration($path);
            if($duration<=0)throw new RuntimeException('The duration of this reusable video could not be read.');
            if($duration>10.05)throw new RuntimeException('Reusable video clips can be no longer than 10 seconds. This file is '.number_format($duration,2).' seconds.');
            $type='SHORT_VIDEO';
        }elseif(in_array($mime,$imageMimes,true)){
            $type=$mime==='image/png'&&$type==='TRANSPARENT_PNG'?'TRANSPARENT_PNG':'IMAGE';
            if($requestedType==='LOGO')$type='LOGO';
            if($requestedType==='OVERLAY')$type='OVERLAY';
        }elseif(!in_array($type,['AUDIO'],true))$type='AUDIO';
        return ['type'=>$type,'mime'=>$mime];
    }

    private function duration(string $path): float
    {
        $process=proc_open([$this->binary(),'-hide_banner','-i',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))return 0;
        $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        return preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/',$output,$match)
            ?(int)$match[1]*3600+(int)$match[2]*60+(float)$match[3]:0;
    }

    private function binary(): string
    {
        $configured=trim((string)($_ENV['FFMPEG_PATH']??''));
        if($configured!==''&&is_file($configured))return $configured;
        if(PHP_OS_FAMILY==='Windows'){
            $matches=glob((getenv('LOCALAPPDATA')?:'').'/Microsoft/WinGet/Packages/Gyan.FFmpeg_*/ffmpeg-*/bin/ffmpeg.exe');
            if($matches)return (string)end($matches);
        }
        return 'ffmpeg';
    }
}
