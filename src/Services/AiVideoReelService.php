<?php

namespace App\Services;

use RuntimeException;

final class AiVideoReelService
{
    public function range(object $job,int $targetDuration,?float $manualStart=null,?float $manualEnd=null): array
    {
        $targetDuration=max(10,min(90,$targetDuration));
        if($manualStart!==null&&$manualEnd!==null&&$manualEnd>$manualStart){
            return ['start'=>max(0,$manualStart),'end'=>min($manualEnd,$manualStart+$targetDuration),'reason'=>'Manual excerpt'];
        }
        $raw=json_decode((string)$job->transcript_json,true);$segments=is_array($raw)?(array)($raw['segments']??[]):[];
        if(!$segments)throw new RuntimeException('Transcribe this source before creating a reel.');
        $keywords=['agent'=>7,'business'=>5,'save'=>6,'important'=>4,'change'=>4,'win'=>7,'start'=>5,'actually'=>3,'difference'=>6,'problem'=>4,'solution'=>5,'why'=>3,'how'=>3,'thousand'=>7,'future'=>4];
        $best=null;$bestScore=-INF;
        foreach($segments as $index=>$segment){
            $text=mb_strtolower((string)($segment['text']??''));$start=(float)($segment['start']??0);$end=(float)($segment['end']??$start);
            if($end-$start<1||mb_strlen($text)<18)continue;
            $score=min(8,mb_strlen($text)/30);
            foreach($keywords as $word=>$weight)if(str_contains($text,$word))$score+=$weight;
            if(preg_match('/\b(um|uh|yeah)\b.{0,8}$/i',$text))$score-=4;
            if($start<15)$score-=6;
            if($score>$bestScore){$bestScore=$score;$best=$index;}
        }
        if($best===null)$best=0;
        $anchorStart=(float)($segments[$best]['start']??0);$anchorEnd=(float)($segments[$best]['end']??$anchorStart+5);
        $start=max(0,$anchorStart-min(8,$targetDuration*.22));$end=$start+$targetDuration;
        $lastEnd=(float)($segments[array_key_last($segments)]['end']??$end);
        if($end>$lastEnd){$end=$lastEnd;$start=max(0,$end-$targetDuration);}
        foreach($segments as $segment){$segmentStart=(float)($segment['start']??0);if($segmentStart<=$start&&$start-(float)$segmentStart<4)$start=$segmentStart;}
        $silences=is_array($raw)?(array)($raw['silences']??[]):[];
        $anchor=$anchorStart;
        foreach($silences as $silence){$silenceEnd=(float)($silence['end']??0);if($silenceEnd<=$anchor&&$anchor-$silenceEnd<=15)$start=$silenceEnd;}
        foreach($silences as $silence){$silenceStart=(float)($silence['start']??0);if($silenceStart>=$start+$targetDuration*.75&&$silenceStart<=$start+$targetDuration+8){$end=$silenceStart;break;}}
        return ['start'=>round($start,3),'end'=>round($end,3),'reason'=>'Automatically selected strongest statement (score '.round($bestScore,1).')'];
    }

    public function plan(object $source,array $range,string $captionStyle,string $instructions=''): array
    {
        $parent=json_decode((string)$source->edit_plan_json,true);if(!is_array($parent))$parent=[];
        $start=(float)$range['start'];$end=(float)$range['end'];
        $overlaps=fn(array $item,string $startField,string $endField):bool=>(float)($item[$endField]??$item[$startField]??0)>=$start&&(float)($item[$startField]??0)<=$end;
        $plan=$parent;
        $plan['cuts']=[['start'=>$start,'end'=>$end,'reason'=>$range['reason']]];
        foreach(['camera_moves'=>['start','end'],'generated_inserts'=>['start','end'],'caption_style_events'=>['start','end'],'text_overlays'=>['start','end']] as $field=>$fields)$plan[$field]=array_values(array_filter((array)($plan[$field]??[]),fn($item)=>is_array($item)&&$overlaps($item,$fields[0],$fields[1])));
        $plan['transitions']=array_values(array_filter((array)($plan['transitions']??[]),fn($item)=>(float)($item['timestamp']??0)>=$start&&(float)($item['timestamp']??0)<=$end));
        $plan['motion_graphics']=array_values(array_filter((array)($plan['motion_graphics']??[]),fn($item)=>(float)($item['timestamp']??0)>=$start&&(float)($item['timestamp']??0)<=$end));
        // Never pretend to know the active speaker by alternating left/right.
        // Keep only intentional parent moves that overlap this excerpt.
        $plan['camera_moves']=array_values(array_filter((array)($plan['camera_moves']??[]),fn($move)=>($move['source']??'')!=='reel'));
        $plan['transitions']=array_values(array_filter((array)($plan['transitions']??[]),fn($transition)=>($transition['source']??'')!=='reel'));
        $plan['caption_style_events'][]=['start'=>$start,'end'=>$end,'preset'=>'neon-pop','size_percent'=>55,'animation'=>'word','source'=>'reel'];
        $request=(array)($plan['_request']??[]);
        $parentPreset=(string)($request['caption_preset']??'classic-bold');
        $parentSize=max(70,min(120,(int)($request['caption_size_percent']??85)));
        $request['timeline_commands']=array_values(array_filter((array)($request['timeline_commands']??[]),static fn($command)=>is_array($command)&&($command['type']??'')!=='caption'&&(float)($command['timestamp']??-1)>=$start&&(float)($command['timestamp']??-1)<=$end));
        // A reel is another editable view over the same source timeline. Keep the
        // parent's deterministic transcript cuts, precision waveform edits,
        // participant identities and approved effects instead of starting with a
        // weaker short-form editor.
        foreach(['removed_segments','timeline_removed_segments','precision_edits'] as $field){
            $request[$field]=array_values(array_filter((array)($request[$field]??[]),static function($segment)use($start,$end):bool{
                return is_array($segment)&&(float)($segment['end']??0)>=$start&&(float)($segment['start']??0)<=$end;
            }));
        }
        $request['pause_edits']=array_values(array_filter((array)($request['pause_edits']??[]),static fn($pause)=>is_array($pause)&&(float)($pause['end']??0)>=$start&&(float)($pause['start']??0)<=$end));
        $request=array_merge($request,[
            'project_kind'=>'reel','parent_project_id'=>(int)$source->id,'aspect_ratio'=>'9:16',
            'caption_style'=>in_array($captionStyle,['kinetic','dynamic','clean'],true)?$captionStyle:'kinetic',
            'caption_preset'=>$parentPreset,'caption_size_percent'=>$parentSize,'render_preset'=>'veryfast','render_crf'=>26,
            'audio_bitrate'=>'160k','intro_url'=>'','outro_url'=>'','vertical_focus_x'=>.5,'reel_range'=>$range,'reel_instructions'=>$instructions,
            'editor_capabilities'=>['transcript_cuts','precision_waveform','silence_cleanup','timed_chips','camera_moves','transitions','media_layers','caption_styles','speaker_profiles'],
        ]);
        $plan['caption_style_events']=[];
        $plan['_request']=$request;
        return $plan;
    }
}
