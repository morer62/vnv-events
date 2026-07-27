<?php
namespace App\Services;

final class AiCaptionEditorService
{
    public function remove(string $transcript,string $srt,array $phrases,array $plan=[]): array
    {
        $phrases=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$phrases))));
        if(!$phrases)return ['transcript'=>$transcript,'srt'=>$srt,'plan'=>$plan,'removed_segments'=>[]];
        $segments=[];$blocks=preg_split('/\R{2,}/',trim($srt));$kept=[];$n=1;
        foreach($blocks as $block){
            $lines=preg_split('/\R/',$block);$timeIndex=isset($lines[1])&&str_contains($lines[1],'-->')?1:0;$time=$lines[$timeIndex]??'';$caption=trim(implode(' ',array_slice($lines,$timeIndex+1)));
            $remove=false;foreach($phrases as $phrase)if($this->contains($caption,$phrase)){$remove=true;break;}
            if($remove&&preg_match('/(\d\d:\d\d:\d\d[,.]\d+)\s+-->\s+(\d\d:\d\d:\d\d[,.]\d+)/',$time,$m))$segments[]=['start'=>$m[1],'end'=>$m[2],'reason'=>'Removed from transcript: '.$caption];
            elseif($caption!=='')$kept[]=$n++."\n".$time."\n".$this->stripPhrases(implode("\n",array_slice($lines,$timeIndex+1)),$phrases);
        }
        $transcript=$this->stripPhrases($transcript,$phrases);$request=(array)($plan['_request']??[]);$request['removed_segments']=array_merge((array)($request['removed_segments']??[]),$segments);$request['removed_phrases']=array_values(array_unique(array_merge((array)($request['removed_phrases']??[]),$phrases)));$plan['_request']=$request;
        return ['transcript'=>trim(preg_replace('/[ \t]{2,}/',' ',$transcript)),'srt'=>implode("\n\n",$kept),'plan'=>$plan,'removed_segments'=>$segments];
    }
    private function contains(string $haystack,string $needle): bool{return mb_stripos($this->normalize($haystack),$this->normalize($needle))!==false;}
    private function stripPhrases(string $text,array $phrases): string{foreach($phrases as $phrase)$text=preg_replace('/(?<![\pL\pN])'.preg_quote($phrase,'/').'(?![\pL\pN])/iu','',$text);return trim(preg_replace('/[ \t]+([,.;!?])/u','$1',$text));}
    private function normalize(string $v): string{return mb_strtolower(trim(preg_replace('/\s+/u',' ',$v)));}
}
