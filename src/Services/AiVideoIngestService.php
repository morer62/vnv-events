<?php

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AiVideoIngestService
{
    private const VIDEO_EXTENSIONS=['mp4','mov','m4v','mkv','webm','avi','mts','m2ts','mpg','mpeg'];
    private const AUDIO_EXTENSIONS=['mp3','m4a','wav','aac','flac','ogg','opus'];
    private const IMAGE_EXTENSIONS=['png','jpg','jpeg','webp','gif'];

    public function ownerRoot(int $ownerId): string
    {
        $configured=trim((string)($_ENV['VIDEO_INGEST_PATH']??''));
        if($configured===''||$this->isAbsolute($configured)||str_contains(str_replace('\\','/',$configured),'../'))$configured='storage/video-ingest/incoming';
        $base=$this->absolutePath($configured);
        if(!is_dir($base)&&!mkdir($base,0770,true)&&!is_dir($base))throw new RuntimeException('Unable to access storage/video-ingest/incoming relative to the project. Create that folder by FTP and make it readable and writable by PHP.');
        $root=rtrim($base,'/\\').DIRECTORY_SEPARATOR.'owner-'.$ownerId;
        if(!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Unable to create the owner video ingest directory.');
        return $root;
    }

    private function absolutePath(string $path): string
    {
        $path=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,trim($path));
        return dirname(__DIR__,2).DIRECTORY_SEPARATOR.ltrim($path,DIRECTORY_SEPARATOR);
    }

    private function isAbsolute(string $path): bool
    {
        $path=str_replace('\\','/',$path);
        return str_starts_with($path,'/')||(bool)preg_match('/^[A-Za-z]:\//',$path);
    }

    public function stableSeconds(): int{return max(30,min(600,(int)($_ENV['VIDEO_INGEST_STABLE_SECONDS']??60)));}

    public function createProjectFolder(int $ownerId,string $name): string
    {
        $name=trim((string)preg_replace('/[^A-Za-z0-9._ ()-]+/','-',trim($name)));
        $name=trim($name,' .-');
        if($name===''||!$this->validSegment($name))throw new RuntimeException('Enter a valid project folder name.');
        $path=$this->ownerRoot($ownerId).DIRECTORY_SEPARATOR.$name;
        if(is_dir($path))throw new RuntimeException('That project folder already exists.');
        if(!mkdir($path,0770,true)&&!is_dir($path))throw new RuntimeException('Unable to create the project folder.');
        foreach(['source','intros','transitions','b-roll','images','logos','music','sound-effects','voice-over','outros'] as $folder){
            $child=$path.DIRECTORY_SEPARATOR.$folder;
            if(!mkdir($child,0770,true)&&!is_dir($child))throw new RuntimeException('Unable to create the '.$folder.' folder.');
        }
        return $name;
    }

    public function prepareBrowserUpload(int $ownerId,string $title,string $originalName): array
    {
        $extension=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));
        if(!in_array($extension,self::VIDEO_EXTENSIONS,true))throw new RuntimeException('Supported project videos: MP4, MOV, M4V, MKV, WebM, AVI, MTS/M2TS, MPG and MPEG.');
        $base=trim((string)preg_replace('/[^A-Za-z0-9._ ()-]+/','-',trim($title)),' .-');
        if($base==='')$base=trim((string)preg_replace('/[^A-Za-z0-9._ ()-]+/','-',pathinfo($originalName,PATHINFO_FILENAME)),' .-');
        $base=mb_substr($base?:'video-project',0,120);$folder=$base;$suffix=2;$root=$this->ownerRoot($ownerId);
        while(is_dir($root.DIRECTORY_SEPARATOR.$folder))$folder=$base.'-'.$suffix++;
        $this->createProjectFolder($ownerId,$folder);
        $safeBase=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo($originalName,PATHINFO_FILENAME)),'-.')?:'master';
        $filename=mb_substr($safeBase,0,130).'.'.$extension;
        $path=$root.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.$filename;
        $url='vnv-local://owner-'.$ownerId.'/'.rawurlencode($folder).'/source/'.rawurlencode($filename);
        return ['folder'=>$folder,'filename'=>$filename,'path'=>$path,'url'=>$url,'mime_type'=>$this->mime($path)];
    }

    public function uploadWorkspace(int $ownerId,string $uploadId): string
    {
        if(!preg_match('/^[a-f0-9]{24,64}$/',$uploadId))throw new RuntimeException('Invalid upload session.');
        $directory=$this->ownerRoot($ownerId).DIRECTORY_SEPARATOR.'.browser-uploads';
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new RuntimeException('Unable to create the browser upload workspace.');
        return $directory.DIRECTORY_SEPARATOR.$uploadId;
    }

    public function folders(int $ownerId): array
    {
        $root=$this->ownerRoot($ownerId);$items=[];
        foreach(scandir($root)?:[] as $folder){
            if($folder==='.'||$folder==='..'||!$this->validSegment($folder))continue;
            $path=$root.DIRECTORY_SEPARATOR.$folder;if(!is_dir($path))continue;
            $files=$this->scan($ownerId,$folder,$path);
            $items[]=[
                'name'=>$folder,'files'=>$files,'videos'=>array_map(fn($file)=>array_merge($file,['name'=>'['.$file['role'].'] '.$file['relative_path']]),$files),
                'ready_count'=>count(array_filter($files,fn($file)=>$file['ready']&&$file['role']==='SOURCE'&&$file['kind']==='video')),
                'ready_sources'=>count(array_filter($files,fn($file)=>$file['ready']&&$file['role']==='SOURCE'&&$file['kind']==='video')),
                'total_bytes'=>array_sum(array_column($files,'bytes')),
            ];
        }
        usort($items,fn($a,$b)=>strcmp($b['name'],$a['name']));return $items;
    }

    public function inventory(int $ownerId,string $folder): array
    {
        if(!$this->validSegment($folder))throw new RuntimeException('Invalid SFTP project folder.');
        $root=$this->ownerRoot($ownerId);$path=$root.DIRECTORY_SEPARATOR.$folder;$real=realpath($path);
        if(!$real||!is_dir($real)||!$this->inside($real,$root))throw new RuntimeException('The SFTP project folder was not found.');
        return $this->scan($ownerId,$folder,$real);
    }

    public function inventoryForSource(int $ownerId,string $sourceUrl): array
    {
        $parts=$this->referenceParts($sourceUrl);if(!$parts||(int)$parts['owner']!==$ownerId||!isset($parts['segments'][0]))return [];
        try{return $this->inventory($ownerId,$parts['segments'][0]);}catch(\Throwable $e){return [];}
    }

    public function importable(int $ownerId,string $folder): array
    {
        $files=array_values(array_filter($this->inventory($ownerId,$folder),fn($item)=>$item['ready']&&$item['role']==='SOURCE'&&$item['kind']==='video'));
        if(!$files)throw new RuntimeException('No completed video was found in source/. Finish the upload, rename the file, and wait at least '.$this->stableSeconds().' seconds.');
        return array_map(fn($item)=>[
            'title'=>$folder.' — '.pathinfo($item['name'],PATHINFO_FILENAME),
            'source_name'=>$item['relative_path'],'source_url'=>$item['url'],'mime_type'=>$item['mime_type'],'bytes'=>$item['bytes'],
        ],$files);
    }

    public function localPath(string $sourceUrl): ?string
    {
        $parts=$this->referenceParts($sourceUrl);if(!$parts)return null;
        $root=$this->ownerRoot((int)$parts['owner']);$candidate=$root;
        foreach($parts['segments'] as $segment)$candidate.=DIRECTORY_SEPARATOR.$segment;
        $real=realpath($candidate);
        if(!$real||!is_file($real)||!$this->inside($real,$root)||!$this->supported($real))throw new RuntimeException('Private project media is unavailable.');
        return $real;
    }

    public function materialize(string $sourceUrl,string $target): string
    {
        $local=$this->localPath($sourceUrl);if($local)return $local;
        $handle=fopen($target,'wb');if(!$handle)throw new RuntimeException('Unable to create the media workspace.');
        $ch=curl_init($sourceUrl);curl_setopt_array($ch,[CURLOPT_FILE=>$handle,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>0,CURLOPT_CONNECTTIMEOUT=>30,CURLOPT_FAILONERROR=>true]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);fclose($handle);
        if(!$ok||$status<200||$status>=300){@unlink($target);throw new RuntimeException('Unable to download project media'.($error?': '.$error:'.'));}
        return $target;
    }

    public function uploadProjectAsset(int $ownerId,string $sourceUrl,array $file,string $role): string
    {
        $parts=$this->referenceParts($sourceUrl);if(!$parts||(int)$parts['owner']!==$ownerId)throw new RuntimeException('This project does not use the private project inbox.');
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('Select a valid project asset.');
        if((int)($file['size']??0)<=0||(int)$file['size']>250*1024*1024)throw new RuntimeException('Browser project assets must be below 250 MB. Use SFTP for larger files.');
        $extension=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));if(!in_array($extension,array_merge(self::VIDEO_EXTENSIONS,self::AUDIO_EXTENSIONS,self::IMAGE_EXTENSIONS),true))throw new RuntimeException('Unsupported project asset format.');
        $folder=match($role){'intro'=>'intros','outro'=>'outros','logo'=>'logos','b-roll'=>'b-roll','music'=>'music','transition'=>'transitions',default=>'images'};
        $projectRoot=$this->ownerRoot($ownerId).DIRECTORY_SEPARATOR.$parts['segments'][0];$destinationFolder=$projectRoot.DIRECTORY_SEPARATOR.$folder;
        if(!is_dir($destinationFolder)&&!mkdir($destinationFolder,0770,true)&&!is_dir($destinationFolder))throw new RuntimeException('Unable to create the project asset folder.');
        $base=preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo((string)$file['name'],PATHINFO_FILENAME));$base=trim((string)$base,'-.')?:'asset';$name=$base.'-'.date('Ymd-His').'.'.$extension;$destination=$destinationFolder.DIRECTORY_SEPARATOR.$name;
        if(!move_uploaded_file((string)$file['tmp_name'],$destination))throw new RuntimeException('Unable to save the project asset.');
        return $folder.'/'.$name;
    }

    public function saveRemoteProjectAsset(int $ownerId,string $sourceUrl,string $remoteUrl,string $name='generated-asset'): array
    {
        $parts=$this->referenceParts($sourceUrl);if(!$parts||(int)$parts['owner']!==$ownerId)throw new RuntimeException('Generated assets require a private SFTP project folder.');
        $ch=curl_init($remoteUrl);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>180,CURLOPT_FAILONERROR=>true]);$binary=curl_exec($ch);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_error($ch);curl_close($ch);
        if(!is_string($binary)||$binary==='')throw new RuntimeException('Unable to download the generated asset'.($error?': '.$error:'.'));
        $extension=str_contains($mime,'jpeg')?'jpg':(str_contains($mime,'webp')?'webp':'png');
        $base=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',mb_substr($name,0,80)),'-.')?:'generated-asset';
        $folder='images';$project=$parts['segments'][0];$destinationFolder=$this->ownerRoot($ownerId).DIRECTORY_SEPARATOR.$project.DIRECTORY_SEPARATOR.$folder;
        if(!is_dir($destinationFolder)&&!mkdir($destinationFolder,0770,true)&&!is_dir($destinationFolder))throw new RuntimeException('Unable to create the generated asset folder.');
        $filename=$base.'-'.date('Ymd-His').'.'.$extension;$destination=$destinationFolder.DIRECTORY_SEPARATOR.$filename;
        if(file_put_contents($destination,$binary)===false)throw new RuntimeException('Unable to save the generated asset in the project folder.');
        $relative=$folder.'/'.$filename;$url='vnv-local://owner-'.$ownerId.'/'.rawurlencode($project).'/'.rawurlencode($folder).'/'.rawurlencode($filename);
        return ['name'=>$filename,'relative_path'=>$relative,'url'=>$url,'mime_type'=>$mime?:'image/png','kind'=>'image','role'=>'IMAGE'];
    }

    private function scan(int $ownerId,string $folder,string $path): array
    {
        $base=(string)realpath($path);if($base==='')return [];
        $files=[];$now=time();$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,RecursiveDirectoryIterator::SKIP_DOTS));
        foreach($iterator as $entry){
            if(!$entry->isFile()||$entry->isLink()||!$this->supported($entry->getPathname()))continue;
            $real=$entry->getRealPath();if(!$real||!$this->inside($real,$base))continue;
            $relative=str_replace('\\','/',substr($real,strlen(rtrim($base,'/\\'))+1));$segments=explode('/',$relative);
            if(count($segments)>6||array_filter($segments,fn($segment)=>!$this->validSegment($segment)))continue;
            $modified=(int)$entry->getMTime();$kind=$this->kind($real);$role=$this->role($segments,$kind);
            $url='vnv-local://owner-'.$ownerId.'/'.implode('/',array_map('rawurlencode',array_merge([$folder],$segments)));
            $files[]=['name'=>$entry->getFilename(),'relative_path'=>$relative,'folder'=>count($segments)>1?implode('/',array_slice($segments,0,-1)):'','kind'=>$kind,'role'=>$role,'mime_type'=>$this->mime($real),'bytes'=>(int)$entry->getSize(),'modified_at'=>date('Y-m-d H:i:s',$modified),'ready'=>$entry->getSize()>0&&($now-$modified)>=$this->stableSeconds(),'url'=>$url];
        }
        usort($files,fn($a,$b)=>strcmp($a['relative_path'],$b['relative_path']));return $files;
    }

    private function referenceParts(string $url): ?array
    {
        if(!str_starts_with($url,'vnv-local://'))return null;
        $parts=parse_url($url);$host=(string)($parts['host']??'');if(!preg_match('/^owner-(\d+)$/',$host,$match))throw new RuntimeException('Invalid private media owner.');
        $segments=array_values(array_filter(array_map('rawurldecode',explode('/',trim((string)($parts['path']??''),'/'))),fn($value)=>$value!==''));
        if(count($segments)<2||count($segments)>7||array_filter($segments,fn($segment)=>!$this->validSegment($segment)))throw new RuntimeException('Invalid private media reference.');
        return ['owner'=>(int)$match[1],'segments'=>$segments];
    }

    private function role(array $segments,string $kind): string
    {
        if(count($segments)===1)return $kind==='video'?'SOURCE':strtoupper($kind);
        $folder=strtolower(preg_replace('/[^a-z0-9]+/','-',(string)$segments[0]));
        return match($folder){
            'source','sources','original','originals'=>'SOURCE','intro','intros'=>'INTRO','outro','outros'=>'OUTRO',
            'transition','transitions'=>'TRANSITION','b-roll','broll'=>'BROLL','music','audio'=>'AUDIO',
            'sound-effects','sfx'=>'SFX','image','images','photo','photos'=>'IMAGE','overlay','overlays'=>'OVERLAY',
            'voice-over','voiceover'=>'VOICEOVER','logo','logos'=>'LOGO','export','exports'=>'EXPORT',default=>'SUPPORT',
        };
    }
    private function supported(string $path): bool{return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),array_merge(self::VIDEO_EXTENSIONS,self::AUDIO_EXTENSIONS,self::IMAGE_EXTENSIONS),true);}
    private function kind(string $path): string{$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));return in_array($extension,self::VIDEO_EXTENSIONS,true)?'video':(in_array($extension,self::AUDIO_EXTENSIONS,true)?'audio':'image');}
    private function validSegment(string $name): bool{return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._ ()-]{0,179}$/',$name)&&!str_contains($name,'..');}
    private function inside(string $path,string $root): bool{$root=rtrim((string)realpath($root),'/\\').DIRECTORY_SEPARATOR;return str_starts_with(strtolower($path.DIRECTORY_SEPARATOR),strtolower($root));}
    private function mime(string $path): string
    {
        return match(strtolower(pathinfo($path,PATHINFO_EXTENSION))){
            'mov'=>'video/quicktime','webm'=>'video/webm','mkv'=>'video/x-matroska','avi'=>'video/x-msvideo','mts','m2ts'=>'video/mp2t',
            'mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav','aac'=>'audio/aac','flac'=>'audio/flac','ogg','opus'=>'audio/ogg',
            'png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif',default=>'video/mp4',
        };
    }
}
