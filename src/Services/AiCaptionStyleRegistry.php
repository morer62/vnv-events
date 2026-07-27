<?php

namespace App\Services;

final class AiCaptionStyleRegistry
{
    public static function all(): array
    {
        return [
            self::style('classic-bold','Classic Bold','Large white creator captions','white','#111111','Arial',1.00,5,false,'none'),
            self::style('tiktok-pop','TikTok Pop','White words with an active yellow pop','white','#111111','Arial',1.08,6,true,'yellow'),
            self::style('neon-mint','Neon Mint','VNV mint emphasis with dark outline','#49f2c2','#07111f','Arial',1.05,6,true,'#ffffff'),
            self::style('yellow-punch','Yellow Punch','High-retention yellow impact captions','#ffe600','#111111','Arial',1.10,7,true,'#ffffff'),
            self::style('karaoke-glow','Karaoke Glow','Word-by-word cyan karaoke glow','#31e8ff','#172554','Arial',1.04,7,true,'#ffffff'),
            self::style('minimal-white','Minimal White','Small polished subtitles','white','#000000','Arial',.76,3,false,'none'),
            self::style('boxed-dark','Boxed Dark','White type on a dark caption box','white','#000000','Arial',.88,2,false,'none',true),
            self::style('creator-blue','Creator Blue','Blue creator captions with white focus','#60a5fa','#0f172a','Arial',1.02,6,true,'white'),
            self::style('hot-pink','Hot Pink','Energetic pink social captions','#ff4faf','#21091a','Arial',1.05,6,true,'white'),
            self::style('lime-impact','Lime Impact','Bold lime fitness-style captions','#b7ff2a','#102000','Arial',1.10,7,true,'white'),
            self::style('orange-energy','Orange Energy','Warm energetic marketing captions','#ff8a1f','#241000','Arial',1.05,6,true,'white'),
            self::style('cinematic','Cinematic','Elegant centered cinematic subtitles','white','#000000','Georgia',.80,4,false,'none'),
            self::style('lower-third','Lower Third','Compact captions aligned lower left','white','#111827','Arial',.76,3,false,'none',true,'left'),
            self::style('comic-pop','Comic Pop','Playful uppercase social captions','#ffffff','#5b21b6','Arial',1.08,8,true,'#facc15'),
            self::style('luxury-gold','Luxury Gold','Premium gold event captions','#e7c66b','#18120a','Georgia',.92,5,false,'#ffffff'),
            self::style('red-alert','Red Alert','Urgent red and white emphasis','#ff3b3b','#240000','Arial',1.08,7,true,'white'),
            self::style('podcast-clean','Podcast Clean','Readable podcast interview captions','white','#0f172a','Arial',.84,4,false,'#7dd3fc',true),
            self::style('word-focus','Word Focus','Only the current word receives emphasis','white','#111111','Arial',1.12,7,true,'#49f2c2'),
            self::style('electric-purple','Electric Purple','Purple captions with bright focus','#c084fc','#1e0a3c','Arial',1.04,7,true,'white'),
            self::style('soft-shadow','Soft Shadow','Calm white captions with subtle shadow','white','#334155','Arial',.86,3,false,'none'),
        ];
    }

    public static function find(string $id): array
    {
        foreach(self::all() as $style)if($style['id']===$id)return $style;
        return self::all()[0];
    }

    private static function style(string $id,string $name,string $description,string $color,string $outline,string $font,float $scale,int $outlineWidth,bool $uppercase,string $active,bool $box=false,string $align='center'): array
    {
        return compact('id','name','description','color','outline','font','scale','outlineWidth','uppercase','active','box','align');
    }
}
