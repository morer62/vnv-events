<?php

namespace App\Services;

use RuntimeException;

final class AiVideoProxyService
{
    public function path(int $ownerId,int $jobId): string
    {
        return dirname(__DIR__,2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'video-proxies'.DIRECTORY_SEPARATOR.'owner-'.$ownerId.DIRECTORY_SEPARATOR.'project-'.$jobId.'-edit-v2.mp4';
    }

    public function exists(int $ownerId,int $jobId): bool
    {
        $path=$this->path($ownerId,$jobId);
        return is_file($path)&&filesize($path)>1024;
    }

    public function version(int $ownerId,int $jobId): int
    {
        $path=$this->path($ownerId,$jobId);
        return is_file($path)?(int)filemtime($path):0;
    }

    public function generate(int $ownerId,object $job): void
    {
        $target=$this->path($ownerId,(int)$job->id);$directory=dirname($target);
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new RuntimeException('Unable to create the private editing-proxy directory.');
        $source=(new AiVideoIngestService())->localPath((string)$job->source_url);
        if(!$source)throw new RuntimeException('Editing proxies currently require a private SFTP source.');
        $temporary=$target.'.building';@set_time_limit(0);
        // The proxy is an editing surface, never a delivery file. A small canvas,
        // fixed frame rate and one-second keyframes make scrubbing much cheaper.
        // Final renders continue reading the untouched source_url master.
        $command=[
            $this->binary(),'-y','-i',$source,
            '-map','0:v:0','-map','0:a:0?',
            '-vf','scale=960:540:force_original_aspect_ratio=decrease,pad=960:540:(ow-iw)/2:(oh-ih)/2,fps=24',
            '-c:v','libx264','-preset','veryfast','-tune','fastdecode','-crf','31',
            '-pix_fmt','yuv420p','-profile:v','main',
            '-g','24','-keyint_min','24','-sc_threshold','0',
            '-c:a','aac','-b:a','64k','-ac','2','-ar','44100',
            '-movflags','+faststart','-f','mp4',$temporary
        ];
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
