<?php

namespace App\Services;

final class AiTranscriptTimelineService
{
    public function timeline(object $job): array
    {
        $raw=json_decode((string)($job->transcript_json??''),true);
        $segments=is_array($raw)?(array)($raw['segments']??[]):[];
        $sourceWords=is_array($raw)?(array)($raw['words']??[]):[];
        $sourceSilences=is_array($raw)?(array)($raw['silences']??[]):[];
        if(!$segments)$segments=$this->segmentsFromSrt((string)($job->subtitles_srt??''));
        $plan=json_decode((string)($job->edit_plan_json??''),true);$request=is_array($plan)?(array)($plan['_request']??[]):[];$storedCommands=(array)($request['timeline_commands']??[]);$storedPauseEdits=(array)($request['pause_edits']??[]);$reelRange=($request['project_kind']??'')==='reel'?(array)($request['reel_range']??[]):[];$rangeStart=(float)($reelRange['start']??0);$rangeEnd=(float)($reelRange['end']??PHP_FLOAT_MAX);$blocks=[];$allWords=[];
        foreach($segments as $index=>$segment){
            if(!is_array($segment))continue;
            $start=max(0,(float)($segment['start']??0));$end=max($start+.01,(float)($segment['end']??$start));
            if($reelRange&&($end<$rangeStart||$start>$rangeEnd))continue;
            $text=trim((string)($segment['text']??''));if($text==='')continue;
            $words=[];
            foreach($sourceWords as $word){
                if(!is_array($word))continue;$wordStart=(float)($word['start']??-1);$wordEnd=(float)($word['end']??-1);
                if($wordStart>=$start-.05&&$wordEnd<=$end+.08)$words[]=['text'=>trim((string)($word['word']??$word['text']??'')),'start'=>$wordStart,'end'=>$wordEnd,'estimated'=>false];
            }
            if(!$words)$words=$this->estimatedWords($text,$start,$end);
            $words=array_values(array_filter($words,fn($word)=>$word['text']!==''));
            foreach($words as $word)$allWords[]=$word;
            $blockCommands=array_values(array_filter($storedCommands,fn($command)=>(int)($command['block_id']??0)===$index+1));
            $blocks[]=['id'=>$index+1,'start'=>$start,'end'=>$end,'time'=>$this->clock($start),'text'=>$text,'words'=>$words,'commands'=>$blockCommands];
        }
        usort($allWords,fn($a,$b)=>$a['start']<=>$b['start']);$pauses=[];
        // FFmpeg audio analysis is authoritative. Estimated word timestamps can place
        // large gaps inside a spoken sentence and must never become destructive pauses.
        if(!$sourceSilences&&$sourceWords)for($i=1;$i<count($allWords);$i++){
            $gap=$allWords[$i]['start']-$allWords[$i-1]['end'];if($gap<.8)continue;
            $pause=['start'=>$allWords[$i-1]['end'],'end'=>$allWords[$i]['start'],'duration'=>$gap,'keep'=>$gap];
            foreach($storedPauseEdits as $edit)if(abs((float)($edit['start']??-1)-$pause['start'])<.05&&abs((float)($edit['end']??-1)-$pause['end'])<.05){$pause['keep']=max(0,min($gap,(float)($edit['keep']??$gap)));break;}
            $pauses[]=$pause;
        }
        foreach($sourceSilences as $silence){
            if(!is_array($silence))continue;$start=max(0,(float)($silence['start']??0));$end=max($start,(float)($silence['end']??$start));if($end-$start<.55)continue;
            if($reelRange&&($end<$rangeStart||$start>$rangeEnd))continue;
            $duplicate=false;foreach($pauses as $pause)if(abs((float)$pause['start']-$start)<.15&&abs((float)$pause['end']-$end)<.15){$duplicate=true;break;}
            if(!$duplicate){
                $keep=$end-$start;foreach($storedPauseEdits as $edit)if(abs((float)($edit['start']??-1)-$start)<.05&&abs((float)($edit['end']??-1)-$end)<.05){$keep=max(0,min($end-$start,(float)($edit['keep']??$keep)));break;}
                $pauses[]=['start'=>$start,'end'=>$end,'duration'=>$end-$start,'keep'=>$keep];
            }
        }
        usort($pauses,fn($a,$b)=>(float)$a['start']<=>(float)$b['start']);
        foreach($blocks as &$block)$block['pauses']=[];
        unset($block);
        foreach($pauses as $pause){
            $target=null;$mid=((float)$pause['start']+(float)$pause['end'])/2;
            foreach($blocks as $index=>$block)if($mid>=(float)$block['start']&&$mid<=(float)$block['end']){$target=$index;break;}
            if($target===null)foreach($blocks as $index=>$block)if((float)$block['start']>=(float)$pause['end']-.2){$target=$index;break;}
            if($target===null&&$blocks)$target=count($blocks)-1;
            if($target!==null)$blocks[$target]['pauses'][]=$pause;
        }
        return ['blocks'=>$blocks,'pauses'=>$pauses,'has_word_timestamps'=>!empty($sourceWords)];
    }

    public function apply(object $job,array $edits,bool $removePauses=false,float $pauseThreshold=1.25,array $pauseEdits=[]): array
    {
        $timeline=$this->timeline($job);$byId=[];foreach($timeline['blocks'] as $block)$byId[(int)$block['id']]=$block;
        $removed=[];$lines=[];$srt=[];$commands=[];$number=1;
        foreach($edits as $edit){
            if(!is_array($edit)||!isset($byId[(int)($edit['id']??0)]))continue;$block=$byId[(int)$edit['id']];
            $text=trim((string)($edit['text']??''));$text=$this->extractCommands($text,$block,$commands);
            $kept=$this->matchedWordIndexes($block['words'],$text);
            foreach($block['words'] as $index=>$word)if(!isset($kept[$index]))$removed[]=['start'=>$word['start'],'end'=>$word['end'],'reason'=>'Deleted from transcript: '.$word['text']];
            if($text==='')continue;
            $keptWords=[];foreach($block['words'] as $index=>$word)if(isset($kept[$index]))$keptWords[]=$word;
            $start=$keptWords?(float)$keptWords[0]['start']:(float)$block['start'];$end=$keptWords?(float)end($keptWords)['end']:(float)$block['end'];
            $lines[]=$text;$srt[]=$number++."\n".$this->srtTime($start).' --> '.$this->srtTime($end)."\n".$text;
        }
        $editedPauses=[];
        foreach($pauseEdits as $pauseEdit){
            if(!is_array($pauseEdit))continue;
            $start=max(0,(float)($pauseEdit['start']??0));$end=max($start,(float)($pauseEdit['end']??$start));
            $keep=max(0,min($end-$start,(float)($pauseEdit['keep']??($end-$start))));
            if($end-$start>$keep+.02){$removed[]=['start'=>$start+$keep,'end'=>$end,'reason'=>'Pause shortened from '.number_format($end-$start,2).'s to '.number_format($keep,2).'s'];$editedPauses[]=['start'=>$start,'end'=>$end,'keep'=>$keep];}
        }
        if($removePauses)foreach($timeline['pauses'] as $pause)if($pause['duration']>=$pauseThreshold&&!$this->pauseWasEdited($pause,$editedPauses))$removed[]=['start'=>$pause['start'],'end'=>$pause['end'],'reason'=>'Long pause '.number_format($pause['duration'],2).'s'];
        $removed=$this->mergeSegments($removed);
        $plan=json_decode((string)($job->edit_plan_json??''),true);if(!is_array($plan))$plan=[];
        $request=(array)($plan['_request']??[]);$previousTimeline=(array)($request['timeline_removed_segments']??[]);$baseRemoved=array_values(array_filter((array)($request['removed_segments']??[]),fn($segment)=>!$this->sameAsAny($segment,$previousTimeline)));$request['timeline_removed_segments']=$removed;$request['removed_segments']=$this->mergeSegments(array_merge($baseRemoved,$removed));$request['timeline_commands']=$commands;$request['pause_edits']=$editedPauses;$request['pause_threshold_seconds']=$pauseThreshold;$plan['_request']=$request;
        foreach(['transitions','camera_moves','generated_inserts','caption_style_events','text_overlays'] as $field)$plan[$field]=array_values(array_filter((array)($plan[$field]??[]),fn($item)=>($item['source']??'')!=='timeline_command'));
        foreach($commands as $command)$this->applyCommandToPlan($plan,$command);
        return ['transcript'=>implode("\n\n",$lines),'srt'=>implode("\n\n",$srt),'plan'=>$plan,'removed_segments'=>$removed,'commands'=>$commands];
    }

    private function matchedWordIndexes(array $words,string $edited): array
    {
        $original=array_map(fn($word)=>$this->normalizeToken((string)$word['text']),$words);
        $changed=array_values(array_filter(array_map(fn($word)=>$this->normalizeToken($word),preg_split('/\s+/u',$edited)?:[]),fn($word)=>$word!==''));
        $n=count($original);$m=count($changed);$dp=array_fill(0,$n+1,array_fill(0,$m+1,0));
        for($i=$n-1;$i>=0;$i--)for($j=$m-1;$j>=0;$j--)$dp[$i][$j]=$original[$i]!==''&&$original[$i]===$changed[$j]?1+$dp[$i+1][$j+1]:max($dp[$i+1][$j],$dp[$i][$j+1]);
        $keep=[];$i=0;$j=0;while($i<$n&&$j<$m){if($original[$i]!==''&&$original[$i]===$changed[$j]){$keep[$i]=true;$i++;$j++;}elseif($dp[$i+1][$j]>=$dp[$i][$j+1])$i++;else$j++;}return $keep;
    }

    private function extractCommands(string $text,array $block,array &$commands): string
    {
        return trim(preg_replace_callback('/\[(transition|zoom|background|image|video|caption|text|out)\s*:\s*([^\]]+)\]/iu',function($match)use($block,&$commands){
            $parts=array_map('trim',explode('|',$match[2]));$instruction=array_shift($parts);$timestamp=(float)$block['start'];
            foreach($parts as $part)if(preg_match('/^at\s*:\s*(\d+(?:\.\d+)?)s?$/i',$part,$atMatch)){$timestamp=max((float)$block['start'],min((float)$block['end'],(float)$atMatch[1]));break;}
            $commands[]=['type'=>mb_strtolower($match[1]),'instruction'=>$instruction,'options'=>$parts,'timestamp'=>$timestamp,'block_id'=>(int)$block['id'],'token'=>$match[0]];return '';
        },$text));
    }

    private function applyCommandToPlan(array &$plan,array $command): void
    {
        $type=$command['type'];$instruction=mb_strtolower((string)$command['instruction']);$at=(float)$command['timestamp'];$options=implode(' | ',(array)$command['options']);
        $value=function(string $key,string $default='')use($options):string{return preg_match('/(?:^|\|)\s*'.preg_quote($key,'/').'\s*:\s*([^|]+)/i',$options,$match)?trim($match[1]):$default;};
        if($type==='caption'){$duration=null;$size=75;$animation='word';if(preg_match('/duration\s*:\s*(\d+(?:\.\d+)?)\s*s/i',$options,$m))$duration=max(.5,min(3600,(float)$m[1]));if(preg_match('/size\s*:\s*(\d{2,3})\s*%/i',$options,$m))$size=max(35,min(140,(int)$m[1]));if(preg_match('/animation\s*:\s*(word|phrase|static)/i',$options,$m))$animation=strtolower($m[1]);$plan['caption_style_events'][]=['start'=>$at,'end'=>$duration===null?null:$at+$duration,'preset'=>AiCaptionStyleRegistry::find((string)$command['instruction'])['id'],'size_percent'=>$size,'animation'=>$animation,'source'=>'timeline_command'];}
        elseif($type==='out'){$out=mb_strtolower((string)$command['instruction']);for($index=count((array)($plan['generated_inserts']??[]))-1;$index>=0;$index--){$insert=$plan['generated_inserts'][$index];if(($insert['source']??'')!=='timeline_command'||(!str_contains(mb_strtolower((string)($insert['asset_name']??'')),$out)&&$out!=='active'))continue;$plan['generated_inserts'][$index]['duration_seconds']=max(.1,$at-(float)$insert['start']);break;}for($index=count((array)($plan['text_overlays']??[]))-1;$index>=0;$index--){$text=$plan['text_overlays'][$index];if(($text['source']??'')!=='timeline_command'||(!str_contains(mb_strtolower((string)($text['name']??'')),$out)&&$out!=='active'))continue;$plan['text_overlays'][$index]['duration_seconds']=max(.1,$at-(float)$text['start']);break;}if($out==='zoom'||$out==='active'){for($index=count((array)($plan['camera_moves']??[]))-1;$index>=0;$index--){$move=$plan['camera_moves'][$index];if(($move['source']??'')!=='timeline_command'||(float)($move['start']??0)>$at)continue;$plan['camera_moves'][$index]['end']=max((float)$move['start']+.1,$at);break;}}}
        elseif($type==='text'){$duration=preg_match('/duration\s*:\s*(\d+(?:\.\d+)?)\s*s/i',$options,$m)?max(.5,min(3600,(float)$m[1])):(str_contains($options,'persistent')?3600:3);$plan['text_overlays'][]=['start'=>$at,'duration_seconds'=>$duration,'name'=>$command['instruction'],'content'=>$command['instruction'],'x'=>max(0,min(100,(float)$value('x','50'))),'y'=>max(0,min(100,(float)$value('y','20'))),'scale'=>max(10,min(300,(float)$value('scale','100'))),'opacity'=>max(0,min(100,(float)$value('opacity','100'))),'rotation'=>max(-360,min(360,(float)$value('rotation','0'))),'layer'=>(int)$value('layer','90'),'font'=>$value('font','Arial'),'color'=>$value('color','#ffffff'),'align'=>$value('align','center'),'background'=>$value('background','#000000'),'background_enabled'=>$value('background_enabled','0')==='1','shadow'=>$value('shadow','1')!=='0','enter'=>$value('enter','fade'),'exit'=>$value('exit','fade'),'notes'=>$value('notes'),'source'=>'timeline_command'];}
        elseif($type==='transition'){$effect=str_contains($instruction,'black')?'dip_to_black':(str_contains($instruction,'whip')?'whip':(str_contains($instruction,'zoom')?'zoom':'flash'));$plan['transitions'][]=['timestamp'=>$at,'type'=>$effect,'duration'=>.22,'reason'=>$command['instruction'].' '.$options,'source'=>'timeline_command'];}
        elseif($type==='zoom'){$focus=str_contains($instruction.' '.$options,'left')?.28:(str_contains($instruction.' '.$options,'right')?.72:.5);$duration=str_contains($options,'persistent')?3600:3;if(preg_match('/duration\s*:\s*(\d+(?:\.\d+)?)\s*s/i',$options,$m))$duration=max(.5,min(3600,(float)$m[1]));$plan['camera_moves'][]=['start'=>$at,'end'=>$at+$duration,'type'=>str_contains($instruction,'out')?'punch_out':'punch_in','zoom'=>1.16,'focus_x'=>$focus,'focus_y'=>.42,'reason'=>$command['instruction'].' '.$options,'source'=>'timeline_command'];}
        else{$duration=str_contains($options,'persistent')?3600:3;if(preg_match('/duration\s*:\s*(\d+(?:\.\d+)?)\s*s/i',$options,$m))$duration=max(.5,min(3600,(float)$m[1]));$assetUrl=$value('asset_url');$placement=$value('target',$type==='background'?'background':'replace');$mime=$value('mime_type',$type==='video'?'video/mp4':'image/png');$plan['generated_inserts'][]=['start'=>$at,'duration_seconds'=>$duration,'prompt'=>$command['instruction'],'asset_name'=>$command['instruction'],'asset_url'=>$assetUrl,'mime_type'=>$mime,'media_type'=>str_starts_with($mime,'video/')?'uploaded_video':'uploaded_image','placement'=>$placement,'x'=>max(0,min(100,(float)$value('x','50'))),'y'=>max(0,min(100,(float)$value('y','50'))),'scale'=>max(10,min(300,(float)$value('scale','100'))),'rotation'=>max(-360,min(360,(float)$value('rotation','0'))),'opacity'=>max(0,min(100,(float)$value('opacity','100'))),'layer'=>(int)$value('layer','60'),'transition_in'=>$value('enter','fade'),'transition_out'=>$value('exit','fade'),'notes'=>$value('notes'),'status'=>$assetUrl!==''?'READY':'PROPOSED','source'=>'timeline_command'];}
    }

    private function estimatedWords(string $text,float $start,float $end): array
    {
        $tokens=preg_split('/\s+/u',trim($text))?:[];$duration=max(.05,$end-$start);$count=max(1,count($tokens));$words=[];
        foreach($tokens as $index=>$token)$words[]=['text'=>$token,'start'=>$start+$duration*$index/$count,'end'=>$start+$duration*($index+1)/$count,'estimated'=>true];
        return $words;
    }

    private function segmentsFromSrt(string $srt): array
    {
        $segments=[];foreach(preg_split('/\R{2,}/',trim($srt))?:[] as $block){$lines=preg_split('/\R/',$block)?:[];$timeIndex=isset($lines[1])&&str_contains($lines[1],'-->')?1:0;if(!preg_match('/(.+?)\s+-->\s+(.+)/',(string)($lines[$timeIndex]??''),$m))continue;$segments[]=['start'=>$this->seconds($m[1]),'end'=>$this->seconds($m[2]),'text'=>trim(implode(' ',array_slice($lines,$timeIndex+1)))];}return $segments;
    }
    private function mergeSegments(array $segments): array{usort($segments,fn($a,$b)=>(float)$a['start']<=>(float)$b['start']);$out=[];foreach($segments as $segment){$segment['start']=(float)$segment['start'];$segment['end']=(float)$segment['end'];$last=count($out)-1;if($last>=0&&$segment['start']<=$out[$last]['end']+.08){$out[$last]['end']=max($out[$last]['end'],$segment['end']);$out[$last]['reason'].='; '.$segment['reason'];}else$out[]=$segment;}return $out;}
    private function sameAsAny(array $segment,array $others): bool{foreach($others as $other)if(abs((float)($segment['start']??0)-(float)($other['start']??0))<.02&&abs((float)($segment['end']??0)-(float)($other['end']??0))<.02)return true;return false;}
    private function pauseWasEdited(array $pause,array $edits): bool{foreach($edits as $edit)if(abs((float)$pause['start']-(float)$edit['start'])<.05&&abs((float)$pause['end']-(float)$edit['end'])<.05)return true;return false;}
    private function normalizeToken(string $value): string{return mb_strtolower(trim(preg_replace('/[^\pL\pN\']+/u','',$value)));}
    private function seconds(string $time): float{$time=str_replace(',','.',$time);$parts=array_map('floatval',explode(':',$time));return count($parts)===3?$parts[0]*3600+$parts[1]*60+$parts[2]:(count($parts)===2?$parts[0]*60+$parts[1]:(float)$time);}
    private function clock(float $seconds): string{return sprintf('%02d:%02d',(int)floor($seconds/60),(int)floor($seconds)%60);}
    private function srtTime(float $seconds): string{$ms=(int)round($seconds*1000);return sprintf('%02d:%02d:%02d,%03d',intdiv($ms,3600000),intdiv($ms%3600000,60000),intdiv($ms%60000,1000),$ms%1000);}
}
