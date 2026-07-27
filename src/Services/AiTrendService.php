<?php
namespace App\Services;

final class AiTrendService
{
    public function currentVideoSignals(string $region='US'): array
    {
        $key=trim((string)($_ENV['YOUTUBE_API_KEY']??''));if($key==='')return ['source'=>'not_configured','items'=>[]];
        $url='https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics&chart=mostPopular&videoCategoryId=22&maxResults=12&regionCode='.rawurlencode($region).'&key='.rawurlencode($key);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($status!==200)return ['source'=>'youtube','items'=>[],'error'=>'HTTP '.$status];
        $data=json_decode((string)$raw,true);$items=[];foreach((array)($data['items']??[]) as $item)$items[]=['title'=>$item['snippet']['title']??'','channel'=>$item['snippet']['channelTitle']??'','published_at'=>$item['snippet']['publishedAt']??'','views'=>(int)($item['statistics']['viewCount']??0)];
        return ['source'=>'youtube_most_popular','fetched_at'=>date('c'),'items'=>$items];
    }
}
