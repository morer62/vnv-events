<?php

namespace App\Services;

use RuntimeException;

final class AiVideoIngestService
{
    private const VIDEO_EXTENSIONS = ['mp4','mov','m4v','mkv','webm','avi','mts','m2ts','mpg','mpeg'];

    public function ownerRoot(int $ownerId): string
    {
        $configured=trim((string)($_ENV['VIDEO_INGEST_PATH']??''));
        $base=$configured!==''?$configured:dirname(__DIR__,2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'video-ingest'.DIRECTORY_SEPARATOR.'incoming';
        if(!is_dir($base)&&!mkdir($base,0770,true)&&!is_dir($base))throw new RuntimeException('Unable to create the private video ingest directory.');
        $root=rtrim($base,'/\\').DIRECTORY_SEPARATOR.'owner-'.$ownerId;
        if(!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Unable to create the owner video ingest directory.');
        return $root;
    }

    public function stableSeconds(): int
    {
        return max(30, min(600, (int)($_ENV['VIDEO_INGEST_STABLE_SECONDS'] ?? 60)));
    }

    public function folders(int $ownerId): array
    {
        $root=$this->ownerRoot($ownerId);$items=[];
        foreach(scandir($root)?:[] as $folder){
            if($folder==='.'||$folder==='..'||!$this->validName($folder))continue;$path=$root.DIRECTORY_SEPARATOR.$folder;if(!is_dir($path))continue;
            $videos=$this->videos($path);$items[]=['name'=>$folder,'videos'=>$videos,'ready_count'=>count(array_filter($videos,fn($v)=>$v['ready'])),'total_bytes'=>array_sum(array_column($videos,'bytes'))];
        }
        usort($items,fn($a,$b)=>strcmp($b['name'],$a['name']));return $items;
    }

    public function importable(int $ownerId,string $folder): array
    {
        if(!$this->validName($folder))throw new RuntimeException('Invalid SFTP project folder.');
        $root=$this->ownerRoot($ownerId);$path=$root.DIRECTORY_SEPARATOR.$folder;$real=realpath($path);
        if(!$real||!is_dir($real)||!$this->inside($real,$root))throw new RuntimeException('The SFTP project folder was not found.');
        $videos=array_values(array_filter($this->videos($real),fn($item)=>$item['ready']));
        if(!$videos)throw new RuntimeException('No completed video files were found. Finish the upload and wait at least '.$this->stableSeconds().' seconds.');
        return array_map(fn($item)=>[
            'title'=>$folder.' — '.pathinfo($item['name'],PATHINFO_FILENAME),
            'source_name'=>$item['name'],
            'source_url'=>'vnv-local://owner-'.$ownerId.'/'.rawurlencode($folder).'/'.rawurlencode($item['name']),
            'mime_type'=>$this->mime($item['name']),
            'bytes'=>$item['bytes'],
        ],$videos);
    }

    public function localPath(string $sourceUrl): ?string
    {
        if(!str_starts_with($sourceUrl,'vnv-local://'))return null;
        $parts=parse_url($sourceUrl);$host=(string)($parts['host']??'');if(!preg_match('/^owner-(\d+)$/',$host,$m))throw new RuntimeException('Invalid private media owner.');
        $segments=array_values(array_filter(array_map('rawurldecode',explode('/',trim((string)($parts['path']??''),'/'))),fn($v)=>$v!==''));
        if(count($segments)!==2||!$this->validName($segments[0])||!$this->validName($segments[1]))throw new RuntimeException('Invalid private media reference.');
        $root=$this->ownerRoot((int)$m[1]);$candidate=$root.DIRECTORY_SEPARATOR.$segments[0].DIRECTORY_SEPARATOR.$segments[1];$real=realpath($candidate);
        if(!$real||!is_file($real)||!$this->inside($real,$root)||!in_array(strtolower(pathinfo($real,PATHINFO_EXTENSION)),self::VIDEO_EXTENSIONS,true))throw new RuntimeException('Private source video is unavailable.');
        return $real;
    }

    public function materialize(string $sourceUrl,string $target): string
    {
        $local=$this->localPath($sourceUrl);if($local)return $local;
        $handle=fopen($target,'wb');if(!$handle)throw new RuntimeException('Unable to create the media workspace.');
        $ch=curl_init($sourceUrl);curl_setopt_array($ch,[CURLOPT_FILE=>$handle,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>0,CURLOPT_CONNECTTIMEOUT=>30,CURLOPT_FAILONERROR=>true]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);fclose($handle);
        if(!$ok||$status<200||$status>=300){@unlink($target);throw new RuntimeException('Unable to download source media'.($error?': '.$error:'.'));}
        return $target;
    }

    private function videos(string $path): array
    {
        $videos=[];$now=time();
        foreach(scandir($path)?:[] as $name){
            if(!$this->validName($name))continue;$file=$path.DIRECTORY_SEPARATOR.$name;if(!is_file($file))continue;$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($extension,self::VIDEO_EXTENSIONS,true))continue;
            $modified=(int)filemtime($file);$videos[]=['name'=>$name,'bytes'=>(int)filesize($file),'modified_at'=>date('Y-m-d H:i:s',$modified),'ready'=>filesize($file)>0&&($now-$modified)>=$this->stableSeconds()];
        }
        usort($videos,fn($a,$b)=>strcmp($a['name'],$b['name']));return $videos;
    }
    private function validName(string $name): bool{return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,159}$/',$name)&&!str_contains($name,'..');}
    private function inside(string $path,string $root): bool{$root=rtrim((string)realpath($root),'/\\').DIRECTORY_SEPARATOR;return str_starts_with(strtolower($path.DIRECTORY_SEPARATOR),strtolower($root));}
    private function mime(string $name): string{return match(strtolower(pathinfo($name,PATHINFO_EXTENSION))){'mov'=>'video/quicktime','webm'=>'video/webm','mkv'=>'video/x-matroska','avi'=>'video/x-msvideo','mts','m2ts'=>'video/mp2t',default=>'video/mp4'};}
}
