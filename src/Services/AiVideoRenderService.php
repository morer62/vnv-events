<?php

namespace App\Services;

use App\Utils\FileUtils;
use RuntimeException;

final class AiVideoRenderService
{
    public function available(): bool
    {
        try { $this->binary(); return true; } catch (\Throwable $e) { return false; }
    }

    public function render(object $job): string
    {
        if (!str_starts_with((string)$job->mime_type, 'video/')) {
            throw new RuntimeException('Final rendering currently requires a video source.');
        }
        $request = [];
        if (!empty($job->edit_plan_json)) {
            $plan = json_decode((string)$job->edit_plan_json, true);
            $request = is_array($plan) ? ($plan['_request'] ?? []) : [];
        }
        $ratio = in_array($request['aspect_ratio'] ?? '', ['9:16','16:9','16:9_4k','1:1','4:5'], true) ? $request['aspect_ratio'] : '16:9';
        [$width,$height] = match($ratio) {
            '9:16' => [1080,1920], '16:9_4k' => [3840,2160], '1:1' => [1080,1080], '4:5' => [1080,1350], default => [1920,1080],
        };
        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vnv-video-' . bin2hex(random_bytes(8));
        if (!mkdir($work, 0700, true) && !is_dir($work)) throw new RuntimeException('Unable to create render workspace.');
        $input=$work.DIRECTORY_SEPARATOR.'source'; $srt=$work.DIRECTORY_SEPARATOR.'captions.srt'; $ass=$work.DIRECTORY_SEPARATOR.'captions.ass'; $logo=$work.DIRECTORY_SEPARATOR.'logo';
        $overlay=$work.DIRECTORY_SEPARATOR.'overlay';$music=$work.DIRECTORY_SEPARATOR.'music';$intro=$work.DIRECTORY_SEPARATOR.'intro';$outro=$work.DIRECTORY_SEPARATOR.'outro';
        $main=$work.DIRECTORY_SEPARATOR.'main.mp4';$output=$work.DIRECTORY_SEPARATOR.'output.mp4';
        try {
            $input=(new AiVideoIngestService())->materialize((string)$job->source_url, $input);
            file_put_contents($srt, (string)$job->subtitles_srt);
            $ingest=new AiVideoIngestService();
            $urls=['logo'=>$request['logo_url']??'','overlay'=>$request['overlay_url']??'','music'=>$request['audio_url']??'','intro'=>$request['intro_url']??'','outro'=>$request['outro_url']??''];
            foreach($urls as $name=>$url)if(trim((string)$url)!=='')${$name}=$ingest->materialize((string)$url,${$name});
            $stylePreset=(string)($request['style_preset']??'vnv_premium');
            $colorFilter=$stylePreset==='marketing_educator'?',eq=contrast=1.08:saturation=1.12:brightness=0.01,unsharp=5:5:0.55:5:5:0.0':',eq=contrast=1.04:saturation=1.06:brightness=0.005';
            $plan=is_array($plan??null)?$plan:[];$removed=(array)($request['removed_segments']??[]);$selection=$this->selectionFilter((array)($plan['cuts']??[]),$removed);
            $insertFiles=[];foreach(array_slice((array)($plan['generated_inserts']??[]),0,8,true) as $key=>$insert){$url=trim((string)($insert['asset_url']??''));if($url==='')continue;$target=$work.DIRECTORY_SEPARATOR.'insert-'.$key;$path=$ingest->materialize($url,$target);$mime=(string)($insert['mime_type']??'');$isVideo=str_starts_with($mime,'video/')||in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['mp4','mov','m4v','mkv','webm','avi','mts','m2ts','mpg','mpeg'],true);$insertFiles[]=['path'=>$path,'video'=>$isVideo,'start'=>$this->seconds((string)($insert['start']??0)),'duration'=>max(.5,min(30,(float)($insert['duration_seconds']??3)))];}
            $filter=$selection."scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height}{$colorFilter}";
            $subtitleFilter='';$captionStyle=(string)($request['caption_style']??'clean');
            if (trim((string)$job->subtitles_srt) !== '' && $captionStyle !== 'none') {
                $this->createKineticAss((string)$job->subtitles_srt,$ass,$width,$height,(array)($plan['caption_animation']['emphasis_words']??[]),(string)($request['caption_preset']??'classic-bold'),(array)($plan['caption_style_events']??[]),$captionStyle,max(70,min(140,(int)($request['caption_size_percent']??100))));
                $captionPath=$ass;$forceStyle='';
                $subtitlePath=str_replace(['\\',':',"'"],['/','\\:',"\\'"],$captionPath);
                $subtitleFilter=",subtitles=filename='{$subtitlePath}'{$forceStyle}";
            }
            $command=[$this->binary(),'-y','-i',$input];$index=1;$logoIndex=$overlayIndex=$musicIndex=null;
            if(is_file($logo)){$command=array_merge($command,['-loop','1','-i',$logo]);$logoIndex=$index++;}
            if(is_file($overlay)){$command=array_merge($command,['-loop','1','-i',$overlay]);$overlayIndex=$index++;}
            if(is_file($music)){$command=array_merge($command,['-stream_loop','-1','-i',$music]);$musicIndex=$index++;}
            $insertIndexes=[];foreach($insertFiles as $insertFile){$command=array_merge($command,$insertFile['video']?['-i',$insertFile['path']]:['-loop','1','-i',$insertFile['path']]);$insertFile['index']=$index++;$insertIndexes[]=$insertFile;}
            $complex="[0:v]{$filter}[base]";$current='base';
            foreach(array_slice((array)($plan['camera_moves']??[]),0,16) as $i=>$move){
                $start=$this->seconds((string)($move['start']??''));$end=$this->seconds((string)($move['end']??''));if($end<=$start)continue;
                $zoom=max(1.02,min(1.22,(float)($move['zoom']??1.08)));$scaledW=(int)ceil($width*$zoom/2)*2;$scaledH=(int)ceil($height*$zoom/2)*2;
                $focusX=max(0,min(1,(float)($move['focus_x']??.5)));$focusY=max(0,min(1,(float)($move['focus_y']??.45)));
                $left=(int)round(($scaledW-$width)*$focusX);$top=(int)round(($scaledH-$height)*$focusY);$next='camera'.$i;
                $complex.=";[{$current}]split=2[keep{$i}][zoomsrc{$i}];[zoomsrc{$i}]scale={$scaledW}:{$scaledH},crop={$width}:{$height}:{$left}:{$top}[zoom{$i}];[keep{$i}][zoom{$i}]overlay=0:0:enable='between(t,{$start},{$end})'[{$next}]";$current=$next;
            }
            foreach(array_slice((array)($plan['transitions']??[]),0,16) as $i=>$transition){
                $at=$this->seconds((string)($transition['timestamp']??''));if($at<=0)continue;$duration=max(.08,min(.5,(float)($transition['duration']??.16)));$type=(string)($transition['type']??'flash');$color=$type==='dip_to_black'?'black@0.82':'white@0.72';$next='transition'.$i;
                $complex.=";[{$current}]drawbox=x=0:y=0:w=iw:h=ih:color={$color}:t=fill:enable='between(t,{$at},".($at+$duration).")'[{$next}]";$current=$next;
            }
            foreach(array_slice((array)($request['overlay_layout']??[]),0,30) as $i=>$overlayText){
                $text=$this->ffmpegText((string)($overlayText['text']??''));if($text==='')continue;$x=max(0,min(.95,(float)($overlayText['x']??.1)));$y=max(0,min(.95,(float)($overlayText['y']??.18)));
                $fontSize=max(24,min((int)round($height*.16),(int)round($height*max(.025,(float)($overlayText['font_size']??.06)))));$start=max(0,(float)($overlayText['start']??0));$end=max($start+.1,(float)($overlayText['end']??99999));$color=preg_match('/^#[0-9a-f]{6}$/i',(string)($overlayText['fill']??''))?(string)$overlayText['fill']:'#ffffff';$next='usertext'.$i;
                $complex.=";[{$current}]drawtext=text='{$text}':fontcolor={$color}:fontsize={$fontSize}:borderw=".max(2,(int)round($height/700)).":bordercolor=black@.8:x=w*{$x}:y=h*{$y}:enable='between(t,{$start},{$end})'[{$next}]";$current=$next;
            }
            if($logoIndex!==null){$complex.=";[{$logoIndex}:v]scale=".max(180,(int)($width*.28)).":-1,format=rgba,fade=t=in:st=0:d=.45:alpha=1,fade=t=out:st=2.5:d=.5:alpha=1[logo];[{$current}][logo]overlay=(W-w)/2:(H-h)/2:enable='between(t,0,3)'[branded]";$current='branded';}
            if($overlayIndex!==null){$complex.=";[{$overlayIndex}:v]scale={$width}:{$height},format=rgba,colorchannelmixer=aa=.38[overlay];[{$current}][overlay]overlay=0:0[overlaid]";$current='overlaid';}
            foreach($insertIndexes as $i=>$insertFile){$next='inserted'.$i;$end=$insertFile['start']+$insertFile['duration'];$frames=max(15,(int)round($insertFile['duration']*30));
                if($insertFile['video'])$complex.=";[{$insertFile['index']}:v]trim=duration={$insertFile['duration']},setpts=PTS-STARTPTS+{$insertFile['start']}/TB,scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},format=rgba[insert{$i}]";
                else $complex.=";[{$insertFile['index']}:v]scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},zoompan=z='min(zoom+0.0015,1.12)':d={$frames}:s={$width}x{$height}:fps=30,setpts=PTS-STARTPTS+{$insertFile['start']}/TB,format=rgba[insert{$i}]";
                $complex.=";[{$current}][insert{$i}]overlay=0:0:enable='between(t,{$insertFile['start']},{$end})'[{$next}]";$current=$next;
            }
            foreach(array_slice((array)($plan['motion_graphics']??[]),0,12) as $i=>$graphic){
                $at=$this->seconds((string)($graphic['timestamp']??''));$text=$this->ffmpegText((string)($graphic['content']??''));if($text==='')continue;
                $next='motion'.$i;$complex.=";[{$current}]drawbox=x=40:y=h*0.16:w=w-80:h=120:color=black@.55:t=fill:enable='between(t,{$at},".($at+3.5).")',drawtext=text='{$text}':fontcolor=white:fontsize=".max(32,(int)($width/24)).":x=(w-text_w)/2:y=h*.19:enable='between(t,{$at},".($at+3.5).")'[{$next}]";$current=$next;
            }
            $complex.=";[{$current}]".ltrim($subtitleFilter,',').($subtitleFilter!==''?'[v]':'null[v]');
            $audio="[0:a]".$this->audioSelectionFilter((array)($plan['cuts']??[]),$removed)."loudnorm=I=-14:TP=-1.5:LRA=11[voice]";
            if($musicIndex!==null)$audio.=";[{$musicIndex}:a]volume=.13[music];[voice][music]amix=inputs=2:duration=first:dropout_transition=2[a]";
            $complex.=';'.$audio;
            $command=array_merge($command,['-filter_complex',$complex,'-map','[v]','-map',$musicIndex!==null?'[a]':'[voice]','-c:v','libx264','-preset','medium','-crf','21','-c:a','aac','-ar','48000','-b:a','192k','-shortest','-movflags','+faststart',$main]);
            $this->execute($command);
            $clips=[];if(is_file($intro)){$normalized=$work.DIRECTORY_SEPARATOR.'intro.mp4';$this->normalizeClip($intro,$normalized,$width,$height);$clips[]=$normalized;}
            $clips[]=$main;
            if(is_file($outro)){$normalized=$work.DIRECTORY_SEPARATOR.'outro.mp4';$this->normalizeClip($outro,$normalized,$width,$height);$clips[]=$normalized;}
            if(count($clips)>1){$list=$work.DIRECTORY_SEPARATOR.'concat.txt';file_put_contents($list,implode("\n",array_map(fn($p)=>"file '".str_replace("'","'\\''",$p)."'",$clips)));$this->execute([$this->binary(),'-y','-f','concat','-safe','0','-i',$list,'-c','copy','-movflags','+faststart',$output]);}
            else copy($main,$output);
            return FileUtils::saveFileFromPath($output,'agents/video-studio/renders','render-'.$job->id.'-'.time());
        } finally {
            foreach (glob($work.DIRECTORY_SEPARATOR.'*')?:[] as $file) if (is_file($file)) @unlink($file);
            if (is_dir($work)) @rmdir($work);
        }
    }

    private function normalizeClip(string $source,string $target,int $width,int $height): void
    {
        $this->execute([$this->binary(),'-y','-i',$source,'-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000',
            '-vf',"scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1",'-map','0:v:0','-map','1:a:0','-c:v','libx264','-preset','medium','-crf','21','-c:a','aac','-ar','48000','-b:a','192k','-shortest',$target]);
    }
    private function selectionFilter(array $cuts,array $removed=[]): string{$expression=$this->timelineExpression($cuts,$removed);return $expression?"select='{$expression}',setpts=N/FRAME_RATE/TB,":'';}
    private function audioSelectionFilter(array $cuts,array $removed=[]): string{$expression=$this->timelineExpression($cuts,$removed);return $expression?"aselect='{$expression}',asetpts=N/SR/TB,":'';}
    private function timelineExpression(array $cuts,array $removed): string
    {
        $keep=[];foreach(array_slice($cuts,0,30) as $c){$a=$this->seconds((string)($c['start']??''));$b=$this->seconds((string)($c['end']??''));if($b>$a)$keep[]="between(t,{$a},{$b})";}
        $drop=[];foreach(array_slice($removed,0,50) as $c){$a=$this->seconds((string)($c['start']??''));$b=$this->seconds((string)($c['end']??''));if($b>$a)$drop[]="between(t,{$a},{$b})";}
        $expression=$keep?'('.implode('+',$keep).')':'1';if($drop)$expression.='*not('.implode('+',$drop).')';return ($keep||$drop)?$expression:'';
    }
    private function seconds(string $time): float{if(is_numeric($time))return max(0,(float)$time);$p=array_map('floatval',explode(':',$time));return count($p)===3?$p[0]*3600+$p[1]*60+$p[2]:(count($p)===2?$p[0]*60+$p[1]:0);}
    private function ffmpegText(string $text): string{return str_replace(["\\","'",':','%'],['\\\\',"\\'",'\\:','\\%'],mb_substr(trim(preg_replace('/\s+/u',' ',$text)),0,100));}

    private function createKineticAss(string $srt,string $target,int $width,int $height,array $emphasisWords=[],string $defaultPreset='classic-bold',array $styleEvents=[],string $captionMode='dynamic',int $defaultSize=100): void
    {
        $header="[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nScaledBorderAndShadow: yes\n\n[V4+ Styles]\n";
        $header.="Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        foreach(AiCaptionStyleRegistry::all() as $style){$fontSize=(int)round($height*($height>$width?.048:.052)*(float)$style['scale']);$margin=(int)round($height*.09);$alignment=$style['align']==='left'?1:2;$border=$style['box']?3:1;$back=$style['box']?'&H96000000':'&H70000000';$secondary=$style['active']==='none'?$style['color']:$style['active'];$header.='Style: '.$style['id'].','.$style['font'].','.$fontSize.','.$this->assColor($style['color']).','.$this->assColor($secondary).','.$this->assColor($style['outline']).','.$back.',-1,0,0,0,100,100,0,0,'.$border.','.max(1,(int)round($style['outlineWidth']*$height/1080)).',1,'.$alignment.',55,55,'.$margin.",1\n";}
        $header.="\n[Events]\n";
        $header.="Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $events=[];$blocks=preg_split('/\R{2,}/',trim($srt))?:[];
        foreach($blocks as $block){
            $lines=preg_split('/\R/',$block)?:[];$time='';$text=[];
            foreach($lines as $line){if(str_contains($line,'-->'))$time=$line;elseif(!preg_match('/^\d+$/',trim($line)))$text[]=$line;}
            if(!preg_match('/(\d\d:\d\d:\d\d[,.]\d+)\s+-->\s+(\d\d:\d\d:\d\d[,.]\d+)/',$time,$m))continue;
            $words=preg_split('/\s+/u',trim(implode(' ',$text)))?:[];if(!$words)continue;$startSeconds=$this->seconds($m[1]);$preset=AiCaptionStyleRegistry::find($defaultPreset)['id'];$sizePercent=$defaultSize;$blockCaptionMode=$captionMode;
            foreach($styleEvents as $styleEvent){$eventStart=(float)($styleEvent['start']??0);$eventEnd=isset($styleEvent['end'])&&$styleEvent['end']!==null?(float)$styleEvent['end']:PHP_FLOAT_MAX;if($startSeconds>=$eventStart&&$startSeconds<=$eventEnd){$preset=AiCaptionStyleRegistry::find((string)($styleEvent['preset']??''))['id'];$sizePercent=max(70,min(140,(int)($styleEvent['size_percent']??100)));$blockCaptionMode=match((string)($styleEvent['animation']??'word')){'static'=>'clean','phrase'=>'dynamic',default=>'kinetic'};}}
            $style=AiCaptionStyleRegistry::find($preset);if($style['uppercase'])$words=array_map(fn($word)=>mb_strtoupper($word),$words);
            $duration=max(.1,$this->seconds($m[2])-$this->seconds($m[1]));$centiseconds=max(1,(int)round($duration*100/count($words)));$karaoke='';
            foreach($words as $word){
                $safe=str_replace(['{','}','\\'],['(',')','\\\\'],$word);
                $isEmphasis=false;foreach($emphasisWords as $emphasis)if(mb_strtolower(trim((string)$emphasis))===mb_strtolower(trim($word,".,!?;:"))){$isEmphasis=true;break;}
                if(in_array($blockCaptionMode,['kinetic','dynamic'],true))$karaoke.='{\\kf'.$centiseconds.($isEmphasis?'\\fscx112\\fscy112':'').'}'.$safe.($isEmphasis?'{\\r'.$preset.'}':'').' ';else$karaoke.=$safe.' ';
            }
            $events[]='Dialogue: 0,'.$this->assTime($m[1]).','.$this->assTime($m[2]).','.$preset.',,0,0,0,,{\\fad(60,80)\\blur0.3\\fscx'.$sizePercent.'\\fscy'.$sizePercent.'}'.trim($karaoke);
        }
        file_put_contents($target,$header.implode("\n",$events)."\n");
    }
    private function assColor(string $color): string{$named=['white'=>'#ffffff','yellow'=>'#ffff00','black'=>'#000000'];$hex=$named[strtolower($color)]??$color;if(!preg_match('/^#([0-9a-f]{6})$/i',$hex,$m))$hex='#ffffff';$value=$m[1];return '&H00'.substr($value,4,2).substr($value,2,2).substr($value,0,2);}
    private function assTime(string $time): string
    {
        $seconds=$this->seconds(str_replace(',', '.', $time));$hours=(int)floor($seconds/3600);$minutes=(int)floor(($seconds%3600)/60);$whole=(int)floor($seconds%60);$centis=(int)round(($seconds-floor($seconds))*100);
        return sprintf('%d:%02d:%02d.%02d',$hours,$minutes,$whole,min(99,$centis));
    }

    private function binary(): string
    {
        $configured=trim((string)($_ENV['FFMPEG_PATH'] ?? ''));
        if ($configured !== '' && is_file($configured)) return $configured;
        if (PHP_OS_FAMILY === 'Windows') {
            $matches=glob((getenv('LOCALAPPDATA') ?: '').'/Microsoft/WinGet/Packages/Gyan.FFmpeg_*/ffmpeg-*/bin/ffmpeg.exe');
            if ($matches) return (string)end($matches);
        }
        $process=proc_open(['ffmpeg','-version'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (is_resource($process)) {
            foreach ($pipes as $pipe) fclose($pipe);
            if (proc_close($process) === 0) return 'ffmpeg';
        }
        throw new RuntimeException('FFmpeg is unavailable. Configure FFMPEG_PATH with the absolute binary path.');
    }

    private function download(string $url, string $target): void
    {
        $handle=fopen($target,'wb'); if(!$handle) throw new RuntimeException('Unable to create source file.');
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$handle,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>600,CURLOPT_CONNECTTIMEOUT=>20]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch); fclose($handle);
        if(!$ok||$status<200||$status>=300) throw new RuntimeException('Unable to download source media'.($error?': '.$error:'.'));
    }

    private function execute(array $command): void
    {
        $nullDevice=PHP_OS_FAMILY==='Windows' ? 'NUL' : '/dev/null';
        $process=proc_open($command,[1=>['file',$nullDevice,'a'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Unable to start FFmpeg.');
        $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code=proc_close($process);
        if($code!==0) throw new RuntimeException('FFmpeg render failed: '.mb_substr(trim($stderr),-1800));
    }
}
