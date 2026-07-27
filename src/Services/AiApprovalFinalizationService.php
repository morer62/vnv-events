<?php

namespace App\Services;

use App\Repositories\Connection;

final class AiApprovalFinalizationService
{
    public function apply(string $actionType, array $payload, int $ownerId): void
    {
        if ($actionType !== 'PUBLISH_ARTICLE' || empty($payload['content_id'])) return;
        $allowed=[];
        foreach(['title','excerpt','body','meta_title','meta_description','featured_image_url'] as $field) if(array_key_exists($field,$payload))$allowed[$field]=$payload[$field];
        if(!$allowed)return;
        $map=['title'=>'title','excerpt'=>'excerpt','body'=>'body_html','meta_title'=>'meta_title','meta_description'=>'meta_description','featured_image_url'=>'featured_image_url'];
        $sets=[];$db=new Connection();
        foreach($allowed as $field=>$value)$sets[]=$map[$field].'=:'.$field;
        $db->query("UPDATE cms_contents SET ".implode(',',$sets).",updated_at=NOW() WHERE id=:id AND id_owner=:owner AND status<>'PUBLISHED'");
        foreach($allowed as $field=>$value)$db->bind(':'.$field,(string)$value);
        $db->bind(':id',(int)$payload['content_id']);$db->bind(':owner',$ownerId);$db->execute();
    }
}
