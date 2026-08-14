<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoIngestService;
use App\Services\LoginService;
use App\Utils\JsonResponse;
use App\Utils\Router;

$router=new Router();
$router->post(function(): JsonResponse {
    try{
        $session=LoginService::getSession();$owner=(int)$session->getOwner();$action=(string)($_POST['upload_action']??'chunk');
        $ingest=new AiVideoIngestService();$uploadId=strtolower(trim((string)($_POST['upload_id']??'')));$workspace=$ingest->uploadWorkspace($owner,$uploadId);$manifestFile=$workspace.'.json';$partFile=$workspace.'.part';
        if($action==='init'){
            $title=trim((string)($_POST['title']??''));$filename=(string)($_POST['filename']??'');$totalBytes=max(0,(int)($_POST['total_bytes']??0));$totalChunks=max(1,(int)($_POST['total_chunks']??0));
            $maxBytes=max(1,min(50,(int)($_ENV['VIDEO_BROWSER_UPLOAD_MAX_GB']??12)))*1073741824;
            if($totalBytes<1||$totalBytes>$maxBytes)throw new RuntimeException('This upload exceeds the configured browser limit of '.round($maxBytes/1073741824).' GB.');
            $free=@disk_free_space($ingest->ownerRoot($owner));if(is_numeric($free)&&$free<$totalBytes*1.15)throw new RuntimeException('The server needs at least 15% more free space than the uploaded master.');
            if(is_file($manifestFile)||is_file($partFile))throw new RuntimeException('This upload session already exists. Reload and try again.');
            $target=$ingest->prepareBrowserUpload($owner,$title,$filename);
            $manifest=['owner'=>$owner,'user'=>(int)$session->getId(),'title'=>$title?:pathinfo($filename,PATHINFO_FILENAME),'original_name'=>$filename,'total_bytes'=>$totalBytes,'total_chunks'=>$totalChunks,'next_chunk'=>0,'received_bytes'=>0,'target'=>$target,'created_at'=>time()];
            if(file_put_contents($manifestFile,json_encode($manifest,JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Unable to start the upload session.');
            return new JsonResponse(['success'=>true,'upload_id'=>$uploadId,'next_chunk'=>0]);
        }
        if(!is_file($manifestFile))throw new RuntimeException('The upload session expired. Start the upload again.');
        $manifest=json_decode((string)file_get_contents($manifestFile),true);if(!is_array($manifest)||(int)($manifest['owner']??0)!==$owner)throw new RuntimeException('Invalid upload session.');
        $index=(int)($_POST['chunk_index']??-1);if($index!==(int)$manifest['next_chunk'])throw new RuntimeException('Unexpected chunk. Expected #'.(int)$manifest['next_chunk'].'.');
        $chunk=$_FILES['chunk']??null;if(!is_array($chunk)||(int)($chunk['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($chunk['tmp_name']??'')))throw new RuntimeException('The browser did not send a valid video chunk.');
        $chunkSize=(int)($chunk['size']??0);if($chunkSize<1||$chunkSize>8*1024*1024)throw new RuntimeException('Invalid upload chunk size.');
        $input=fopen((string)$chunk['tmp_name'],'rb');$output=fopen($partFile,'ab');if(!$input||!$output)throw new RuntimeException('Unable to write the upload chunk.');
        if(!flock($output,LOCK_EX)){fclose($input);fclose($output);throw new RuntimeException('Unable to lock the upload file.');}
        stream_copy_to_stream($input,$output);fflush($output);flock($output,LOCK_UN);fclose($input);fclose($output);
        $manifest['received_bytes']=(int)$manifest['received_bytes']+$chunkSize;$manifest['next_chunk']=$index+1;
        $complete=(int)$manifest['next_chunk']===(int)$manifest['total_chunks'];
        if(!$complete){file_put_contents($manifestFile,json_encode($manifest,JSON_UNESCAPED_SLASHES),LOCK_EX);return new JsonResponse(['success'=>true,'next_chunk'=>$manifest['next_chunk'],'received_bytes'=>$manifest['received_bytes'],'complete'=>false]);}
        clearstatcache(true,$partFile);$actual=(int)filesize($partFile);if($actual!==(int)$manifest['total_bytes'])throw new RuntimeException('Upload size mismatch. Expected '.$manifest['total_bytes'].' bytes and received '.$actual.'.');
        $target=(array)$manifest['target'];if(!rename($partFile,(string)$target['path']))throw new RuntimeException('Unable to finalize the uploaded master.');@chmod((string)$target['path'],0660);
        $projectId=(new AiAgentMediaRepository())->add($owner,(int)$manifest['user'],['title'=>(string)$manifest['title'],'source_url'=>(string)$target['url'],'source_name'=>'source/'.(string)$target['filename'],'mime_type'=>(string)$target['mime_type']]);
        @unlink($manifestFile);
        return new JsonResponse(['success'=>true,'complete'=>true,'project_id'=>$projectId,'redirect'=>'panel/growth-hub/video-studio?project='.$projectId.'&autoproxy=1']);
    }catch(Throwable $e){return new JsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
});
$router->run();
