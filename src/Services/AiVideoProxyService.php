<?php

namespace App\Services;

use RuntimeException;

final class AiVideoProxyService
{
    public function path(int $ownerId,int $jobId): string
    {
        return dirname(__DIR__,2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'video-proxies'.DIRECTORY_SEPARATOR.'owner-'.$ownerId.DIRECTORY_SEPARATOR.'project-'.$jobId.'.mp4';
    }

    public function exists(int $ownerId,int $jobId): bool
    {
        $path=$this->path($ownerId,$jobId);
        return is_file($path)&&filesize($path)>1024;
    }

    public function generate(int $ownerId,object $job): void
    {
        $target=$this->path($ownerId,(int)$job->id);$directory=dirname($target);
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new RuntimeException('Unable to create the private editing-proxy directory.');
        $source=(new AiVideoIngestService())->localPath((string)$job->source_url);
        if(!$source)throw new RuntimeException('Editing proxies currently require a private SFTP source.');
        $temporary=$target.'.building';@set_time_limit(0);
        $command=[$this->binary(),'-y','-i',$source,'-map','0:v:0','-map','0:a:0?','-vf','scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2','-c:v','libx264','-preset','veryfast','-crf','29','-c:a','aac','-b:a','96k','-ac','2','-movflags','+faststart','-f','mp4',$temporary];
        $process=proc_open($command,[1=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Unable to start the editing proxy.');
        $error=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
        if($code!==0||!is_file($temporary)){@unlink($temporary);throw new RuntimeException('Editing proxy failed: '.mb_substr(trim($error),-900));}
        if(is_file($target))@unlink($target);
        if(!rename($temporary,$target)){@unlink($temporary);throw new RuntimeException('Unable to activate the editing proxy.');}
    }

    private function binary(): string
    {
        $configured=trim((string)($_ENV['FFMPEG_PATH']??''));
        if($configured!==''&&is_file($configured))return $configured;
        if(PHP_OS_FAMILY==='Windows'){$matches=glob((getenv('LOCALAPPDATA')?:'').'/Microsoft/WinGet/Packages/Gyan.FFmpeg_*/ffmpeg-*/bin/ffmpeg.exe');if($matches)return (string)end($matches);}
        return 'ffmpeg';
    }
}
