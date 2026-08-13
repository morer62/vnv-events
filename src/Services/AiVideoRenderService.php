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

    public function render(object $job,?callable $progress=null): string
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
        $main=$work.DIRECTORY_SEPARATOR.'main.mp4';$output=$work.DIRECTORY_SEPARATOR.'output.mp4';$workingMaster=$work.DIRECTORY_SEPARATOR.'working-master.mp4';
        try {
            if($progress)$progress(3,'Preparing source');
            $input=(new AiVideoIngestService())->materialize((string)$job->source_url, $input);
            file_put_contents($srt, (string)$job->subtitles_srt);
            $ingest=new AiVideoIngestService();
            $urls=['logo'=>$request['logo_url']??'','overlay'=>$request['overlay_url']??'','music'=>$request['audio_url']??'','intro'=>$request['intro_url']??'','outro'=>$request['outro_url']??''];
            foreach($urls as $name=>$url)if(trim((string)$url)!=='')${$name}=$ingest->materialize((string)$url,${$name});
            $stylePreset=(string)($request['style_preset']??'vnv_premium');
            $colorFilter=$stylePreset==='marketing_educator'?',eq=contrast=1.08:saturation=1.12:brightness=0.01,unsharp=5:5:0.55:5:5:0.0':',eq=contrast=1.04:saturation=1.06:brightness=0.005';
            $plan=is_array($plan??null)?$plan:[];$removed=(array)($request['removed_segments']??[]);$selection=$this->selectionFilter((array)($plan['cuts']??[]),$removed);
            $expectedDuration=$this->editedDuration($input,(array)($plan['cuts']??[]),$removed);
            $renderInput=$input;$sourceSelection=$selection;$sourceAudioSelection=$this->audioSelectionFilter((array)($plan['cuts']??[]),$removed);
            // A long 4K master must not be fed through dozens of serial overlays and
            // transitions. Decode, cut and resize it once, then edit the bounded 1080p
            // working master. This also gives the UI a measurable first render stage.
            if($ratio==='16:9'&&$expectedDuration>90){
                if($progress)$progress(7,'Building optimized editing master');
                $cacheDirectory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'vnv-video-cache';if(!is_dir($cacheDirectory))mkdir($cacheDirectory,0700,true);
                $cacheKey=hash('sha256',$input.'|'.(string)@filesize($input).'|'.(string)@filemtime($input).'|'.$width.'x'.$height.'|'.$selection.'|'.$sourceAudioSelection);
                $cachedMaster=$cacheDirectory.DIRECTORY_SEPARATOR.$cacheKey.'.mp4';
                if(!is_file($cachedMaster)||filesize($cachedMaster)<1024*1024){$this->createWorkingMaster($input,$workingMaster,$width,$height,$selection,$sourceAudioSelection);if(!@rename($workingMaster,$cachedMaster)){copy($workingMaster,$cachedMaster);}}
                $renderInput=$cachedMaster;$sourceSelection='';$sourceAudioSelection='';
            }
            $insertFiles=[];foreach(array_slice((array)($plan['generated_inserts']??[]),0,20,true) as $key=>$insert){$url=trim((string)($insert['asset_url']??''));if($url==='')continue;$mappedStart=$this->timelineOutputTime($this->seconds((string)($insert['start']??0)),(array)($plan['cuts']??[]),$removed);if($mappedStart===null)continue;$target=$work.DIRECTORY_SEPARATOR.'insert-'.$key;$path=$ingest->materialize($url,$target);$mime=(string)($insert['mime_type']??'');$isVideo=str_starts_with($mime,'video/')||in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['mp4','mov','m4v','mkv','webm','avi','mts','m2ts','mpg','mpeg'],true);$placement=(string)($insert['placement']??'replace');$motion=in_array((string)($insert['motion']??'zoom-in'),['zoom-in','zoom-out','none'],true)?(string)($insert['motion']??'zoom-in'):'zoom-in';$insertFiles[]=['path'=>$path,'video'=>$isVideo,'has_audio'=>$isVideo&&$placement==='insert-block'&&$this->hasAudio($path),'start'=>$mappedStart,'duration'=>max(.1,min(3600,(float)($insert['duration_seconds']??3))),'placement'=>$placement,'x'=>max(0,min(100,(float)($insert['x']??50))),'y'=>max(0,min(100,(float)($insert['y']??50))),'scale'=>max(10,min(300,(float)($insert['scale']??100))),'rotation'=>max(-360,min(360,(float)($insert['rotation']??0))),'opacity'=>max(0,min(100,(float)($insert['opacity']??100))),'layer'=>(int)($insert['layer']??60),'motion'=>$motion,'enter'=>(string)($insert['transition_in']??'fade'),'enter_duration'=>max(.1,min(10,(float)($insert['transition_in_duration']??.3))),'exit'=>(string)($insert['transition_out']??'fade'),'exit_duration'=>max(.1,min(10,(float)($insert['transition_out_duration']??.3)))];}
            usort($insertFiles,fn($a,$b)=>$a['layer']<=>$b['layer']);
            $blockInsertFiles=array_values(array_filter($insertFiles,fn($item)=>$item['placement']==='insert-block'&&$item['video']));
            $insertFiles=array_values(array_filter($insertFiles,fn($item)=>$item['placement']!=='insert-block'));
            $verticalFocus=max(0,min(1,(float)($request['vertical_focus_x']??.5)));
            $cropPosition=$ratio==='9:16'?":x='(iw-ow)*{$verticalFocus}':y='(ih-oh)/2'":'';
            $filter=$sourceSelection."scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height}{$cropPosition}{$colorFilter}";
            $subtitleFilter='';$captionStyle=(string)($request['caption_style']??'clean');
            if (trim((string)$job->subtitles_srt) !== '' && $captionStyle !== 'none') {
                $transcriptRaw=json_decode((string)($job->transcript_json??''),true);
                $this->createKineticAss((string)$job->subtitles_srt,$ass,$width,$height,(array)($plan['caption_animation']['emphasis_words']??[]),(string)($request['caption_preset']??'classic-bold'),(array)($plan['caption_style_events']??[]),$captionStyle,max(35,min(140,(int)($request['caption_size_percent']??75))),(array)($plan['cuts']??[]),$removed,is_array($transcriptRaw)?(array)($transcriptRaw['words']??[]):[]);
                $captionPath=$ass;$forceStyle='';$fontDirectory='';
                if(PHP_OS_FAMILY==='Windows'){
                    $fontDirectory=$work.DIRECTORY_SEPARATOR.'fonts';if(!is_dir($fontDirectory))mkdir($fontDirectory,0700,true);
                    foreach(['arial.ttf','arialbd.ttf'] as $font)if(is_file('C:/Windows/Fonts/'.$font))copy('C:/Windows/Fonts/'.$font,$fontDirectory.DIRECTORY_SEPARATOR.$font);
                }
                $subtitlePath=str_replace(['\\',':',"'"],['/','\\:',"\\'"],$captionPath);
                $fontsDirectory=$fontDirectory!==''?":fontsdir='".str_replace(['\\',':',"'"],['/','\\:',"\\'"],$fontDirectory)."'":'';
                $subtitleFilter=",subtitles=filename='{$subtitlePath}'{$fontsDirectory}{$forceStyle}";
            }
            $command=[$this->binary(),'-y','-i',$renderInput];$index=1;$logoIndex=$overlayIndex=$musicIndex=null;
            if(is_file($logo)){$command=array_merge($command,['-loop','1','-i',$logo]);$logoIndex=$index++;}
            if(is_file($overlay)){$command=array_merge($command,['-loop','1','-i',$overlay]);$overlayIndex=$index++;}
            if(is_file($music)){$command=array_merge($command,['-stream_loop','-1','-i',$music]);$musicIndex=$index++;}
            $insertIndexes=[];foreach($insertFiles as $insertFile){$command=array_merge($command,$insertFile['video']?['-i',$insertFile['path']]:['-loop','1','-i',$insertFile['path']]);$insertFile['index']=$index++;$insertIndexes[]=$insertFile;}
            if($ratio==='9:16'){
                $complex="[0:v]{$sourceSelection}split=2[verticalbg][verticalfg];[verticalbg]scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},boxblur=24:3,eq=brightness=-0.18:saturation=.72[vbg];[verticalfg]scale={$width}:{$height}:force_original_aspect_ratio=decrease[vin];[vbg][vin]overlay=(W-w)/2:(H-h)/2{$colorFilter}[base]";
            }else $complex="[0:v]{$filter}[base]";
            $current='base';
            // One timeline-aware zoompan replaces N split/scale/overlay branches.
            // The old graph created hundreds of threads and could take many hours.
            $cameraMoves=[];
            foreach(array_slice((array)($plan['camera_moves']??[]),0,30) as $move){
                $start=$this->timelineOutputTime($this->seconds((string)($move['start']??'')),(array)($plan['cuts']??[]),$removed);$end=$this->timelineOutputTime($this->seconds((string)($move['end']??'')),(array)($plan['cuts']??[]),$removed);
                if($start===null||$end===null||$end<=$start)continue;
                $cameraMoves[]=['start'=>$start,'end'=>min($expectedDuration,$end),'zoom'=>max(1.0,min(1.40,(float)($move['zoom']??1.08))),'x'=>max(0,min(1,(float)($move['focus_x']??.5))),'y'=>max(0,min(1,(float)($move['focus_y']??.45)))];
            }
            if($cameraMoves){
                usort($cameraMoves,fn(array $a,array $b)=>(float)$a['start']<=>(float)$b['start']);
                // Every speaker move is an independent wide -> speaker -> wide arc.
                // This prevents the camera from travelling through empty space when
                // the active speaker changes. Even-pixel crop coordinates also avoid
                // the one-pixel chroma rounding that looks like camera shake.
                $z='1';$focusX='.5';$focusY='.45';
                foreach($cameraMoves as $move){
                    $duration=max(.1,(float)$move['end']-(float)$move['start']);
                    $dramatic=(float)$move['zoom']>=1.23;
                    $inDuration=max(.1,min($dramatic?.65:$duration*.68,$duration*.75));
                    $outDuration=max(.1,min($dramatic?.65:1.2,$duration*.25));
                    $inProgress="max(0\\,min(1\\,(it-{$move['start']})/{$inDuration}))";
                    $outProgress="max(0\\,min(1\\,({$move['end']}-it)/{$outDuration}))";
                    // Quintic smootherstep has zero velocity and acceleration at
                    // both ends, which reads as a real camera operator easing the
                    // lens rather than a CSS-style positional interpolation.
                    $easeIn="({$inProgress})*({$inProgress})*({$inProgress})*(({$inProgress})*(6*({$inProgress})-15)+10)";
                    $easeOut="({$outProgress})*({$outProgress})*({$outProgress})*(({$outProgress})*(6*({$outProgress})-15)+10)";
                    $envelope="min({$easeIn}\\,{$easeOut})";
                    $zoomDuring='1+'.((float)$move['zoom']-1)."*({$envelope})";
                    $xDuring='.5+'.((float)$move['x']-.5)."*({$envelope})";
                    $yDuring='.45+'.((float)$move['y']-.45)."*({$envelope})";
                    $z="if(between(it\\,{$move['start']}\\,{$move['end']})\\,{$zoomDuring}\\,{$z})";
                    $focusX="if(between(it\\,{$move['start']}\\,{$move['end']})\\,{$xDuring}\\,{$focusX})";
                    $focusY="if(between(it\\,{$move['start']}\\,{$move['end']})\\,{$yDuring}\\,{$focusY})";
                }
                // Work at 2x resolution so a one-pixel crop movement represents only
                // half an output pixel.  Quantising the old crop to even pixels made
                // long, shallow pushes advance in visible two-pixel steps (the
                // apparent camera shake reported during slow zooms).
                $smoothWidth=$width*2;$smoothHeight=$height*2;
                $x="floor((iw-iw/zoom)*({$focusX}))";$y="floor((ih-ih/zoom)*({$focusY}))";
                $complex.=";[{$current}]scale={$smoothWidth}:{$smoothHeight}:flags=lanczos,zoompan=z='{$z}':x='{$x}':y='{$y}':d=1:s={$width}x{$height}:fps=30[camera]";$current='camera';
            }
            foreach(array_slice((array)($plan['transitions']??[]),0,30) as $i=>$transition){
                $at=$this->timelineOutputTime($this->seconds((string)($transition['timestamp']??'')),(array)($plan['cuts']??[]),$removed);if($at===null||$at<=0)continue;$duration=max(.1,min(10,(float)($transition['duration']??.3)));$type=(string)($transition['type']??'flash');$next='transition'.$i;
                if(in_array($type,['whip_left','whip_right','whip'],true)){$direction=$type==='whip_right'?-1:1;$complex.=";[{$current}]crop=iw:ih:x='if(between(t,{$at},".($at+$duration)."),{$direction}*18*sin((t-{$at})/{$duration}*PI),0)':y=0,pad=iw+36:ih:18:0:color=black,crop={$width}:{$height}:18:0[{$next}]";}
                elseif($type==='blur'){$end=$at+$duration;$mix="sin(PI*(T-{$at})/{$duration})";$complex.=";[{$current}]split=2[blursharp{$i}][blurinput{$i}];[blurinput{$i}]gblur=sigma=12:steps=2:enable='between(t,{$at},{$end})'[blurred{$i}];[blursharp{$i}][blurred{$i}]blend=all_expr='if(between(T\\,{$at}\\,{$end})\\,A*(1-{$mix})+B*{$mix}\\,A)'[{$next}]";}
                else{$color=in_array($type,['dip_to_black','soft_fade'],true)?'black@0.48':'white@0.28';$complex.=";[{$current}]drawbox=x=0:y=0:w=iw:h=ih:color={$color}:t=fill:enable='between(t,{$at},".($at+$duration).")'[{$next}]";}
                $current=$next;
            }
            foreach(array_slice((array)($request['overlay_layout']??[]),0,30) as $i=>$overlayText){
                $text=$this->ffmpegText((string)($overlayText['text']??''));if($text==='')continue;$x=max(0,min(.95,(float)($overlayText['x']??.1)));$y=max(0,min(.95,(float)($overlayText['y']??.18)));
                $fontSize=max(24,min((int)round($height*.16),(int)round($height*max(.025,(float)($overlayText['font_size']??.06)))));$start=max(0,(float)($overlayText['start']??0));$end=max($start+.1,(float)($overlayText['end']??99999));$color=preg_match('/^#[0-9a-f]{6}$/i',(string)($overlayText['fill']??''))?(string)$overlayText['fill']:'#ffffff';$next='usertext'.$i;
                $fontFile=PHP_OS_FAMILY==='Windows'?"fontfile='C\\:/Windows/Fonts/arialbd.ttf':":'';
                $complex.=";[{$current}]drawtext={$fontFile}text='{$text}':fontcolor={$color}:fontsize={$fontSize}:borderw=".max(2,(int)round($height/700)).":bordercolor=black@.8:x=w*{$x}:y=h*{$y}:enable='between(t,{$start},{$end})'[{$next}]";$current=$next;
            }
            if($logoIndex!==null){$complex.=";[{$logoIndex}:v]scale=".max(180,(int)($width*.28)).":-1,format=rgba,fade=t=in:st=0:d=0.45:alpha=1,fade=t=out:st=2.5:d=0.5:alpha=1[logo];[{$current}][logo]overlay=(W-w)/2:(H-h)/2:eof_action=pass:shortest=1:enable='between(t,0,3)'[branded]";$current='branded';}
            if($overlayIndex!==null){$complex.=";[{$overlayIndex}:v]scale={$width}:{$height},format=rgba,colorchannelmixer=aa=.38[overlay];[{$current}][overlay]overlay=0:0:eof_action=pass:shortest=1[overlaid]";$current='overlaid';}
            foreach($insertIndexes as $i=>$insertFile){$next='inserted'.$i;$start=$insertFile['start'];$end=$start+$insertFile['duration'];$frames=max(3,(int)round(min(60,$insertFile['duration'])*30));$overlay=str_starts_with($insertFile['placement'],'overlay')||$insertFile['placement']==='picture-in-picture';$assetWidth=$overlay?max(120,(int)round($width*.34*$insertFile['scale']/100)):$width;$assetHeight=$overlay?max(120,(int)round($height*.34*$insertFile['scale']/100)):$height;$enterDuration=min($insertFile['enter_duration'],max(.05,$insertFile['duration']/2));$exitDuration=min($insertFile['exit_duration'],max(.05,$insertFile['duration']/2));$fadeOut=max($start,$end-$exitDuration);
                [$baseX,$baseY]=$overlay?["W*".($insertFile['x']/100)."-w/2","H*".($insertFile['y']/100)."-h/2"]:['0','0'];
                $x=$baseX;$y=$baseY;
                if($insertFile['enter']==='slide-left')$x="'if(lt(t,".($start+$enterDuration)."),{$baseX}-((".($start+$enterDuration)."-t)/{$enterDuration})*W,{$baseX})'";
                elseif($insertFile['enter']==='slide-up')$y="'if(lt(t,".($start+$enterDuration)."),{$baseY}+(((".($start+$enterDuration)."-t)/{$enterDuration})*H),{$baseY})'";
                if($insertFile['exit']==='slide-right')$x="'if(gt(t,{$fadeOut}),{$baseX}+((t-{$fadeOut})/{$exitDuration})*W,{$baseX})'";
                elseif($insertFile['exit']==='slide-down')$y="'if(gt(t,{$fadeOut}),{$baseY}+((t-{$fadeOut})/{$exitDuration})*H,{$baseY})'";
                $position=$x.':'.$y;$animation='';
                if(in_array($insertFile['enter'],['fade','pop'],true))$animation.=",fade=t=in:st={$start}:d={$enterDuration}:alpha=1";
                if(in_array($insertFile['exit'],['fade','pop'],true))$animation.=",fade=t=out:st={$fadeOut}:d={$exitDuration}:alpha=1";
                $transform=",format=rgba,colorchannelmixer=aa=".($insertFile['opacity']/100).($insertFile['rotation']!=0?",rotate=".($insertFile['rotation']*M_PI/180).":fillcolor=none:ow=rotw(iw):oh=roth(ih)":'').$animation;
                if($insertFile['video'])$complex.=";[{$insertFile['index']}:v]trim=duration={$insertFile['duration']},setpts=PTS-STARTPTS+{$start}/TB,scale={$assetWidth}:{$assetHeight}:force_original_aspect_ratio=".($overlay?'decrease':'increase').($overlay?'':",crop={$width}:{$height}").$transform."[insert{$i}]";
                else{$motionProgress="min(1\\,on/{$frames})";$ease='('.$motionProgress.')*('.$motionProgress.')*(3-2*('.$motionProgress.'))';$motionExpression=match($insertFile['motion']){'zoom-out'=>'1.08-0.08*'.$ease,'none'=>'1.0',default=>'1.0+0.08*'.$ease};$complex.=";[{$insertFile['index']}:v]scale={$assetWidth}:{$assetHeight}:force_original_aspect_ratio=".($overlay?'decrease':'increase').($overlay?'':",crop={$width}:{$height}").",zoompan=z='{$motionExpression}':d={$frames}:s={$assetWidth}x{$assetHeight}:fps=30,trim=duration={$insertFile['duration']},setpts=PTS-STARTPTS+{$start}/TB".$transform."[insert{$i}]";}
                $complex.=";[{$current}][insert{$i}]overlay={$position}:eof_action=pass:enable='between(t,{$start},{$end})'[{$next}]";$current=$next;
            }
            foreach(array_slice((array)($plan['text_overlays']??[]),0,20) as $i=>$textOverlay){$at=$this->timelineOutputTime((float)($textOverlay['start']??0),(array)($plan['cuts']??[]),$removed);if($at===null)continue;$rawText=trim((string)($textOverlay['content']??''));if($rawText==='')continue;$textFile=$work.DIRECTORY_SEPARATOR.'overlay-text-'.$i.'.txt';file_put_contents($textFile,$this->wrapOverlayText($rawText,30));$escapedTextFile=str_replace(['\\',':',"'"],['/','\\:',"\\'"],$textFile);$end=$at+max(.1,min(3600,(float)($textOverlay['duration_seconds']??3)));$x=max(0,min(100,(float)($textOverlay['x']??50)))/100;$y=max(0,min(100,(float)($textOverlay['y']??20)))/100;$opacity=max(0,min(100,(float)($textOverlay['opacity']??100)))/100;$fontSize=max(20,(int)round($height*.055*max(.1,(float)($textOverlay['scale']??100))/100));$color=preg_match('/^#[0-9a-f]{6}$/i',(string)($textOverlay['color']??''))?(string)$textOverlay['color']:'#ffffff';$background=preg_match('/^#[0-9a-f]{6}$/i',(string)($textOverlay['background']??''))?(string)$textOverlay['background']:'#000000';$fontFile=PHP_OS_FAMILY==='Windows'?"fontfile='C\\:/Windows/Fonts/arialbd.ttf':":'';$box=!empty($textOverlay['background_enabled'])?":box=1:boxcolor={$background}@.72:boxborderw=18":'';$shadow=!empty($textOverlay['shadow'])?':shadowx=3:shadowy=3:shadowcolor=black@.8':'';$align=(string)($textOverlay['align']??'center');$xExpression=$align==='left'?"w*{$x}":($align==='right'?"w*{$x}-text_w":"w*{$x}-text_w/2");$yExpression="h*{$y}-text_h/2";$enter=(string)($textOverlay['enter']??'fade');$exit=(string)($textOverlay['exit']??'fade');$enterDuration=min(max(.1,(float)($textOverlay['enter_duration']??.3)),max(.05,($end-$at)/2));$exitDuration=min(max(.1,(float)($textOverlay['exit_duration']??.3)),max(.05,($end-$at)/2));$fadeIn=min($end,$at+$enterDuration);$fadeOut=max($at,$end-$exitDuration);if($enter==='slide-left')$xExpression="if(lt(t\\,{$fadeIn})\\,-text_w+(t-{$at})/{$enterDuration}*(w*{$x}+text_w)\\,{$xExpression})";elseif($enter==='slide-up')$yExpression="if(lt(t\\,{$fadeIn})\\,h+(t-{$at})/{$enterDuration}*(h*{$y}-text_h/2-h)\\,{$yExpression})";if($exit==='slide-right')$xExpression="if(gt(t\\,{$fadeOut})\\,{$xExpression}+(t-{$fadeOut})/{$exitDuration}*w\\,{$xExpression})";elseif($exit==='slide-down')$yExpression="if(gt(t\\,{$fadeOut})\\,{$yExpression}+(t-{$fadeOut})/{$exitDuration}*h\\,{$yExpression})";$alpha=(in_array($enter,['fade','pop'],true)||in_array($exit,['fade','pop'],true))?"{$opacity}*if(lt(t\\,{$fadeIn})\\,(t-{$at})/{$enterDuration}\\,if(gt(t\\,{$fadeOut})\\,({$end}-t)/{$exitDuration}\\,1))":(string)$opacity;$next='chiptext'.$i;$complex.=";[{$current}]drawtext={$fontFile}textfile='{$escapedTextFile}':fontcolor={$color}:alpha='{$alpha}':fontsize={$fontSize}:borderw=2:bordercolor=black@.55{$shadow}{$box}:x='{$xExpression}':y='{$yExpression}':enable='between(t,{$at},{$end})'[{$next}]";$current=$next;}
            foreach(array_slice((array)($plan['motion_graphics']??[]),0,12) as $i=>$graphic){
                $sourceAt=$this->seconds((string)($graphic['timestamp']??''));$at=$this->timelineOutputTime($sourceAt,(array)($plan['cuts']??[]),$removed);$text=$this->ffmpegText((string)($graphic['content']??''));if($at===null||$text==='')continue;
                $coveredByCard=false;foreach((array)($plan['text_overlays']??[]) as $card){if(trim((string)($card['content']??''))!==''&&abs((float)($card['start']??-999)-$sourceAt)<=4){$coveredByCard=true;break;}}
                if($coveredByCard)continue;
                $fontFile=PHP_OS_FAMILY==='Windows'?"fontfile='C\\:/Windows/Fonts/arialbd.ttf':":'';
                $next='motion'.$i;$complex.=";[{$current}]drawbox=x=40:y=ih*0.16:w=iw-80:h=120:color=black@0.55:t=fill:enable='between(t,{$at},".($at+3.5).")',drawtext={$fontFile}text='{$text}':fontcolor=white:fontsize=".max(32,(int)($width/24)).":x=(w-text_w)/2:y=h*0.19:enable='between(t,{$at},".($at+3.5).")'[{$next}]";$current=$next;
            }
            $complex.=";[{$current}]".ltrim($subtitleFilter,',').($subtitleFilter!==''?'[v]':'null[v]');
            // Rebuild monotonically-timed audio after every transcript cut. This
            // prevents tiny timestamp discontinuities from sounding like stutters,
            // especially near the end of long exports.
            $audio="[0:a]".$sourceAudioSelection."aresample=48000:async=1000:first_pts=0,loudnorm=I=-14:TP=-1.5:LRA=11[voice0]";$audioLabel='voice0';$blockNumber=0;
            if($musicIndex!==null){$audio.=";[{$musicIndex}:a]aresample=48000:async=1000:first_pts=0,volume=.13[music];[{$audioLabel}][music]amix=inputs=2:duration=first:dropout_transition=3,aresample=48000:async=1000:first_pts=0[a]";$audioLabel='a';}
            $complex.=';'.$audio;
            $filterScript=$work.DIRECTORY_SEPARATOR.'filter-complex.txt';file_put_contents($filterScript,$complex);
            $filterOption=$this->supportsFileFilterOption()?'-/filter_complex':'-filter_complex_script';
            $renderPreset=in_array((string)($request['render_preset']??''),['veryfast','fast','medium'],true)?(string)$request['render_preset']:'medium';
            $renderCrf=max(18,min(30,(int)($request['render_crf']??($renderPreset==='veryfast'?23:21))));
            $audioBitrate=in_array((string)($request['audio_bitrate']??''),['96k','128k','160k','192k'],true)?(string)$request['audio_bitrate']:'192k';
            $command=array_merge($command,[$filterOption,$filterScript,'-map','[v]','-map','['.$audioLabel.']','-c:v','libx264','-preset',$renderPreset,'-crf',(string)$renderCrf,'-c:a','aac','-ar','48000','-b:a',$audioBitrate,'-t',(string)$expectedDuration,'-shortest','-movflags','+faststart',$main]);
            if($progress)$progress(12,'Encoding edited video');
            $this->execute($command);
            if($progress)$progress(90,'Joining intro and outro');
            $editedMain=$main;
            if($blockInsertFiles){$composed=$work.DIRECTORY_SEPARATOR.'main-with-inserts.mp4';$this->composeInsertBlocks($main,$blockInsertFiles,$composed,$work,$width,$height,$renderPreset,$renderCrf,$audioBitrate);$editedMain=$composed;}
            $clips=[];if(is_file($intro)){$normalized=$work.DIRECTORY_SEPARATOR.'intro.mp4';$this->normalizeClip($intro,$normalized,$width,$height);$clips[]=$normalized;}
            $clips[]=$editedMain;
            if(is_file($outro)){$normalized=$work.DIRECTORY_SEPARATOR.'outro.mp4';$this->normalizeClip($outro,$normalized,$width,$height);$clips[]=$normalized;}
            if(count($clips)>1){$list=$work.DIRECTORY_SEPARATOR.'concat.txt';file_put_contents($list,implode("\n",array_map(fn($p)=>"file '".str_replace("'","'\\''",$p)."'",$clips)));$this->execute([$this->binary(),'-y','-f','concat','-safe','0','-i',$list,'-c','copy','-movflags','+faststart',$output]);}
            else copy($editedMain,$output);
            if($progress)$progress(97,'Uploading final MP4');
            if(filesize($output)>100*1024*1024)return $this->saveLargeRenderLocally($output,(int)$job->id);
            return FileUtils::saveFileFromPath($output,'agents/video-studio/renders','render-'.$job->id.'-'.time());
        } finally {
            foreach (glob($work.DIRECTORY_SEPARATOR.'*')?:[] as $file) if (is_file($file)) @unlink($file);
            $fontDirectory=$work.DIRECTORY_SEPARATOR.'fonts';foreach(glob($fontDirectory.DIRECTORY_SEPARATOR.'*')?:[] as $file)if(is_file($file))@unlink($file);if(is_dir($fontDirectory))@rmdir($fontDirectory);
            if (is_dir($work)) @rmdir($work);
        }
    }

    private function saveLargeRenderLocally(string $source,int $jobId): string
    {
        $relative='uploads/video-studio/renders/render-'.$jobId.'-'.time().'.mp4';$target=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);$folder=dirname($target);
        if(!is_dir($folder)&&!mkdir($folder,0770,true)&&!is_dir($folder))throw new RuntimeException('Unable to create local video delivery storage.');
        if(!copy($source,$target))throw new RuntimeException('Unable to store the large final MP4 locally.');
        $base=rtrim((string)($_ENV['APP_URL']??''),'/');return ($base!==''?$base:'').'/'.$relative;
    }

    private function createWorkingMaster(string $source,string $target,int $width,int $height,string $videoSelection,string $audioSelection): void
    {
        $video="[0:v]{$videoSelection}scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1[v]";
        $audio="[0:a]{$audioSelection}aresample=48000[a]";
        $this->execute([
            $this->binary(),'-y','-i',$source,
            '-filter_complex',$video.';'.$audio,
            '-map','[v]','-map','[a]',
            '-c:v','libx264','-preset','ultrafast','-crf','18',
            '-c:a','aac','-ar','48000','-b:a','192k',
            '-movflags','+faststart',$target,
        ]);
    }

    private function composeInsertBlocks(string $main,array $blocks,string $target,string $work,int $width,int $height,string $preset,int $crf,string $audioBitrate): void
    {
        usort($blocks,fn($a,$b)=>(float)$a['start']<=>(float)$b['start']);
        $duration=$this->mediaDuration($main);if($duration<=0)throw new RuntimeException('Unable to read the edited master duration before inserting video blocks.');
        $command=[$this->binary(),'-y','-i',$main];$normalized=[];$accepted=[];$cursor=0.0;
        foreach($blocks as $index=>$block){
            $start=max($cursor,min($duration,(float)$block['start']));if($start>=$duration+.01)continue;
            $clip=$work.DIRECTORY_SEPARATOR.'block-insert-'.$index.'.mp4';$clipDuration=max(.5,(float)$block['duration']);
            $this->normalizeInsertClip((string)$block['path'],$clip,$width,$height,$clipDuration);$command=array_merge($command,['-i',$clip]);$normalized[]=$clip;$accepted[]=['start'=>$start,'duration'=>$clipDuration,'index'=>count($accepted)+1];$cursor=$start;
        }
        if(!$accepted){copy($main,$target);return;}
        $parts=[];$labels=[];$cursor=0.0;$segment=0;
        foreach($accepted as $block){
            $start=(float)$block['start'];
            if($start>$cursor+.01){$v='basev'.$segment;$a='basea'.$segment;$parts[]="[0:v]trim=start={$cursor}:end={$start},setpts=PTS-STARTPTS[{$v}]";$parts[]="[0:a]atrim=start={$cursor}:end={$start},asetpts=PTS-STARTPTS,aresample=48000:async=1000:first_pts=0[{$a}]";$labels[]="[{$v}][{$a}]";$segment++;}
            $v='clipv'.$segment;$a='clipa'.$segment;$input=(int)$block['index'];$parts[]="[{$input}:v]setpts=PTS-STARTPTS[{$v}]";$parts[]="[{$input}:a]asetpts=PTS-STARTPTS,aresample=48000:async=1000:first_pts=0[{$a}]";$labels[]="[{$v}][{$a}]";$segment++;$cursor=$start;
        }
        if($cursor<$duration-.01){$v='basev'.$segment;$a='basea'.$segment;$parts[]="[0:v]trim=start={$cursor}:end={$duration},setpts=PTS-STARTPTS[{$v}]";$parts[]="[0:a]atrim=start={$cursor}:end={$duration},asetpts=PTS-STARTPTS,aresample=48000:async=1000:first_pts=0[{$a}]";$labels[]="[{$v}][{$a}]";}
        $parts[]=implode('',$labels).'concat=n='.count($labels).':v=1:a=1[v][a]';
        $this->execute(array_merge($command,['-filter_complex',implode(';',$parts),'-map','[v]','-map','[a]','-c:v','libx264','-preset',$preset,'-crf',(string)$crf,'-c:a','aac','-ar','48000','-b:a',$audioBitrate,'-movflags','+faststart',$target]));
    }

    private function normalizeInsertClip(string $source,string $target,int $width,int $height,float $duration): void
    {
        $audio=$this->hasAudio($source);
        $command=[$this->binary(),'-y','-i',$source];
        if(!$audio)$command=array_merge($command,['-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000']);
        $fadeOut=max(0.1,$duration-.35);
        $videoFilter="scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1,fade=t=in:st=0:d=0.25,fade=t=out:st={$fadeOut}:d=0.35";
        $audioFilter="afade=t=in:st=0:d=0.20,afade=t=out:st={$fadeOut}:d=0.35";
        $command=array_merge($command,['-t',(string)$duration,'-vf',$videoFilter,'-af',$audioFilter,'-map','0:v:0','-map',$audio?'0:a:0':'1:a:0','-c:v','libx264','-preset','fast','-crf','21','-c:a','aac','-ar','48000','-b:a','192k','-shortest',$target]);
        $this->execute($command);
    }

    private function mediaDuration(string $path): float
    {
        $probe=dirname($this->binary()).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY==='Windows'?'ffprobe.exe':'ffprobe');if(!is_file($probe))$probe=PHP_OS_FAMILY==='Windows'?'ffprobe.exe':'ffprobe';
        $process=proc_open([$probe,'-v','error','-show_entries','format=duration','-of','default=noprint_wrappers=1:nokey=1',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))return 0;
        $value=trim((string)stream_get_contents($pipes[1]));stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);return is_numeric($value)?(float)$value:0;
    }

    private function editedDuration(string $source,array $cuts,array $removed): float
    {
        $duration=$this->mediaDuration($source);if($duration<=0)throw new RuntimeException('Unable to read source duration before rendering.');
        if(!$cuts)return max(.1,$duration-$this->removedDuration(0,$duration,$removed));
        $kept=0.0;foreach($cuts as $cut){$start=max(0,$this->seconds((string)($cut['start']??0)));$end=min($duration,$this->seconds((string)($cut['end']??0)));if($end>$start)$kept+=max(0,($end-$start)-$this->removedDuration($start,$end,$removed));}
        return max(.1,min($duration,$kept));
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
    private function timelineOutputTime(float $sourceTime,array $cuts,array $removed): ?float
    {
        if(!$cuts)return max(0,$sourceTime-$this->removedDuration(0,$sourceTime,$removed));
        $elapsed=0.0;
        foreach($cuts as $cut){
            $start=$this->seconds((string)($cut['start']??0));$end=$this->seconds((string)($cut['end']??0));if($end<=$start)continue;
            $kept=max(0,($end-$start)-$this->removedDuration($start,$end,$removed));
            if($sourceTime<$start)return null;
            if($sourceTime<=$end)return $elapsed+max(0,($sourceTime-$start)-$this->removedDuration($start,$sourceTime,$removed));
            $elapsed+=$kept;
        }
        return null;
    }
    private function removedDuration(float $from,float $to,array $removed): float
    {
        $duration=0.0;foreach($removed as $segment){$start=max($from,(float)($segment['start']??0));$end=min($to,(float)($segment['end']??$start));if($end>$start)$duration+=$end-$start;}return $duration;
    }
    private function seconds(string $time): float{$time=str_replace(',','.',$time);if(is_numeric($time))return max(0,(float)$time);$p=array_map('floatval',explode(':',$time));return count($p)===3?$p[0]*3600+$p[1]*60+$p[2]:(count($p)===2?$p[0]*60+$p[1]:0);}
    private function ffmpegText(string $text): string{return str_replace(["\\","'",':','%'],['\\\\',"\\'",'\\:','\\%'],mb_substr(trim(preg_replace('/\s+/u',' ',$text)),0,100));}
    private function wrapOverlayText(string $text,int $maxCharacters): string
    {
        $words=preg_split('/\s+/u',trim($text))?:[];$lines=[];$line='';
        foreach($words as $word){$candidate=$line===''?$word:$line.' '.$word;if($line!==''&&mb_strlen($candidate)>$maxCharacters){$lines[]=$line;$line=$word;}else{$line=$candidate;}}
        if($line!=='')$lines[]=$line;return implode(PHP_EOL,array_slice($lines,0,5));
    }

    private function createKineticAss(string $srt,string $target,int $width,int $height,array $emphasisWords=[],string $defaultPreset='classic-bold',array $styleEvents=[],string $captionMode='dynamic',int $defaultSize=100,array $cuts=[],array $removed=[],array $timedWords=[]): void
    {
        $header="[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nScaledBorderAndShadow: yes\n\n[V4+ Styles]\n";
        $header.="Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        foreach(AiCaptionStyleRegistry::all() as $style){$fontSize=(int)round($height*($height>$width?.048:.052)*(float)$style['scale']);$margin=(int)round($height*.09);$alignment=$style['align']==='left'?1:2;$border=$style['box']?3:1;$back=$style['box']?'&H96000000':'&H70000000';$secondary=$style['active']==='none'?$style['color']:$style['active'];$font=PHP_OS_FAMILY==='Windows'?'Arial':$style['font'];$header.='Style: '.$style['id'].','.$font.','.$fontSize.','.$this->assColor($style['color']).','.$this->assColor($secondary).','.$this->assColor($style['outline']).','.$back.',-1,0,0,0,100,100,0,0,'.$border.','.max(1,(int)round($style['outlineWidth']*$height/1080)).',1,'.$alignment.',55,55,'.$margin.",1\n";}
        $header.="\n[Events]\n";
        $header.="Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $events=[];$blocks=preg_split('/\R{2,}/',trim($srt))?:[];
        foreach($blocks as $block){
            $lines=preg_split('/\R/',$block)?:[];$time='';$text=[];
            foreach($lines as $line){if(str_contains($line,'-->'))$time=$line;elseif(!preg_match('/^\d+$/',trim($line)))$text[]=$line;}
            if(!preg_match('/(\d\d:\d\d:\d\d[,.]\d+)\s+-->\s+(\d\d:\d\d:\d\d[,.]\d+)/',$time,$m))continue;
            $words=preg_split('/\s+/u',trim(implode(' ',$text)))?:[];if(!$words)continue;$sourceStart=$this->seconds($m[1]);$sourceEnd=$this->seconds($m[2]);[$sourceStart,$sourceEnd]=$this->captionIntersection($sourceStart,$sourceEnd,$cuts);if($sourceEnd<=$sourceStart)continue;$startSeconds=$this->timelineOutputTime($sourceStart,$cuts,$removed);$endSeconds=$this->timelineOutputTime($sourceEnd,$cuts,$removed);if($startSeconds===null||$endSeconds===null||$endSeconds<=$startSeconds)continue;$preset=AiCaptionStyleRegistry::find($defaultPreset)['id'];$sizePercent=$defaultSize;$letterSpacing=0.0;$wordSpacing=4.0;$blockCaptionMode=$captionMode;
            foreach($styleEvents as $styleEvent){$eventStart=(float)($styleEvent['start']??0);$eventEnd=isset($styleEvent['end'])&&$styleEvent['end']!==null?(float)$styleEvent['end']:PHP_FLOAT_MAX;if($sourceStart>=$eventStart&&$sourceStart<=$eventEnd){$preset=AiCaptionStyleRegistry::find((string)($styleEvent['preset']??''))['id'];$sizePercent=max(35,min(140,(int)($styleEvent['size_percent']??75)));$letterSpacing=max(-2,min(12,(float)($styleEvent['letter_spacing']??0)));$wordSpacing=max(0,min(24,(float)($styleEvent['word_spacing']??4)));$blockCaptionMode=match((string)($styleEvent['animation']??'word')){'static'=>'clean','phrase'=>'dynamic',default=>'kinetic'};}}
            $style=AiCaptionStyleRegistry::find($preset);if($style['uppercase'])$words=array_map(fn($word)=>mb_strtoupper($word),$words);
            $duration=max(.1,$endSeconds-$startSeconds);
            $wordsPerCaption=$blockCaptionMode==='kinetic'?6:($blockCaptionMode==='dynamic'?6:8);
            $wordSeparator=$wordSpacing<=0?' ':str_repeat('\\h',max(1,min(6,(int)ceil($wordSpacing/4))));
            $exactWords=array_values(array_filter($timedWords,static fn($word)=>is_array($word)&&(float)($word['end']??0)>=$sourceStart-.03&&(float)($word['start']??0)<=$sourceEnd+.03));
            if($exactWords){
                foreach(array_chunk($exactWords,$wordsPerCaption) as $chunk){
                    $chunkSourceStart=max($sourceStart,(float)($chunk[0]['start']??$sourceStart));$chunkSourceEnd=min($sourceEnd,(float)($chunk[array_key_last($chunk)]['end']??$sourceEnd));
                    $chunkStart=$this->timelineOutputTime($chunkSourceStart,$cuts,$removed);$chunkEnd=$this->timelineOutputTime($chunkSourceEnd,$cuts,$removed);if($chunkStart===null||$chunkEnd===null||$chunkEnd<=$chunkStart)continue;
                    $karaoke='';foreach($chunk as $wordIndex=>$word){$value=trim((string)($word['word']??$word['text']??''));if($value==='')continue;if($blockCaptionMode==='kinetic'&&$wordIndex===3)$karaoke.='\\N';if($style['uppercase'])$value=mb_strtoupper($value);$safe=str_replace(['{','}','\\'],['(',')','\\\\'],$value);$wordDuration=max(1,(int)round(((float)($word['end']??0)-(float)($word['start']??0))*100));$isEmphasis=false;foreach($emphasisWords as $emphasis)if(mb_strtolower(trim((string)$emphasis))===mb_strtolower(trim($value,".,!?;:"))){$isEmphasis=true;break;}$karaoke.=in_array($blockCaptionMode,['kinetic','dynamic'],true)?'{\\kf'.$wordDuration.($isEmphasis?'\\fscx112\\fscy112':'').'}'.$safe.($isEmphasis?'{\\r'.$preset.'}':'').$wordSeparator:$safe.$wordSeparator;}
                    $events[]='Dialogue: 0,'.$this->assTimestamp($chunkStart).','.$this->assTimestamp($chunkEnd).','.$preset.',,0,0,0,,{\\fad(60,80)\\blur0.3\\fsp'.$letterSpacing.'\\fscx'.$sizePercent.'\\fscy'.$sizePercent.'}'.trim($karaoke);
                }
                continue;
            }
            $chunks=array_chunk($words,$wordsPerCaption);$wordOffset=0;
            foreach($chunks as $chunk){
                $chunkStart=$startSeconds+$duration*$wordOffset/count($words);$chunkEnd=$startSeconds+$duration*($wordOffset+count($chunk))/count($words);
                $centiseconds=max(1,(int)round(($chunkEnd-$chunkStart)*100/count($chunk)));$karaoke='';
                foreach($chunk as $wordIndex=>$word){
                    if($blockCaptionMode==='kinetic'&&$wordIndex===3)$karaoke.='\\N';
                    $safe=str_replace(['{','}','\\'],['(',')','\\\\'],$word);
                    $isEmphasis=false;foreach($emphasisWords as $emphasis)if(mb_strtolower(trim((string)$emphasis))===mb_strtolower(trim($word,".,!?;:"))){$isEmphasis=true;break;}
                    if(in_array($blockCaptionMode,['kinetic','dynamic'],true))$karaoke.='{\\kf'.$centiseconds.($isEmphasis?'\\fscx112\\fscy112':'').'}'.$safe.($isEmphasis?'{\\r'.$preset.'}':'').$wordSeparator;else$karaoke.=$safe.$wordSeparator;
                }
                $events[]='Dialogue: 0,'.$this->assTimestamp($chunkStart).','.$this->assTimestamp($chunkEnd).','.$preset.',,0,0,0,,{\\fad(60,80)\\blur0.3\\fsp'.$letterSpacing.'\\fscx'.$sizePercent.'\\fscy'.$sizePercent.'}'.trim($karaoke);
                $wordOffset+=count($chunk);
            }
        }
        file_put_contents($target,$header.implode("\n",$events)."\n");
    }
    private function captionIntersection(float $start,float $end,array $cuts): array
    {
        if(!$cuts)return [$start,$end];
        foreach($cuts as $cut){$cutStart=$this->seconds((string)($cut['start']??0));$cutEnd=$this->seconds((string)($cut['end']??0));$overlapStart=max($start,$cutStart);$overlapEnd=min($end,$cutEnd);if($overlapEnd>$overlapStart)return [$overlapStart,$overlapEnd];}
        return [0,0];
    }
    private function assColor(string $color): string{$named=['white'=>'#ffffff','yellow'=>'#ffff00','black'=>'#000000'];$hex=$named[strtolower($color)]??$color;if(!preg_match('/^#([0-9a-f]{6})$/i',$hex,$m))$hex='#ffffff';$value=$m[1];return '&H00'.substr($value,4,2).substr($value,2,2).substr($value,0,2);}
    private function assTime(string $time): string
    {
        return $this->assTimestamp($this->seconds(str_replace(',', '.', $time)));
    }
    private function assTimestamp(float $seconds): string
    {
        $hours=(int)floor($seconds/3600);$minutes=(int)floor(($seconds%3600)/60);$whole=(int)floor($seconds%60);$centis=(int)round(($seconds-floor($seconds))*100);
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
    private function supportsFileFilterOption(): bool
    {
        $process=proc_open([$this->binary(),'-version'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))return false;
        $output=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        return preg_match('/ffmpeg version\s+(\d+)/i',$output,$match)&&(int)$match[1]>=7;
    }

    private function download(string $url, string $target): void
    {
        $handle=fopen($target,'wb'); if(!$handle) throw new RuntimeException('Unable to create source file.');
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$handle,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>600,CURLOPT_CONNECTTIMEOUT=>20]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch); fclose($handle);
        if(!$ok||$status<200||$status>=300) throw new RuntimeException('Unable to download source media'.($error?': '.$error:'.'));
    }

    private function hasAudio(string $path): bool
    {
        $probe=dirname($this->binary()).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY==='Windows'?'ffprobe.exe':'ffprobe');
        if(!is_file($probe))$probe=PHP_OS_FAMILY==='Windows'?'ffprobe.exe':'ffprobe';
        $process=proc_open([$probe,'-v','error','-select_streams','a:0','-show_entries','stream=index','-of','csv=p=0',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))return false;
        $output=trim((string)stream_get_contents($pipes[1]));fclose($pipes[1]);fclose($pipes[2]);return proc_close($process)===0&&$output!=='';
    }

    private function execute(array $command): void
    {
        $nullDevice=PHP_OS_FAMILY==='Windows' ? 'NUL' : '/dev/null';
        $process=proc_open($command,[1=>['file',$nullDevice,'a'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Unable to start FFmpeg.');
        $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code=proc_close($process);
        if($code!==0) throw new RuntimeException('FFmpeg render failed (exit '.$code.'): '.mb_substr(trim($stderr),-6000));
    }
}
