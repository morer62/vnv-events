<?php
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
$router=new Router();$router->get(function(){
 $s=LoginService::getSession();$owner=(int)$s->getOwner();$selected=(int)($_GET['id']??0);$db=new Connection();
 $db->query("SELECT c.*,(SELECT message_text FROM ai_agent_conversation_messages m WHERE m.id_conversation=c.id ORDER BY m.id DESC LIMIT 1) last_message FROM ai_agent_conversations c WHERE c.id_owner=:owner ORDER BY c.last_message_at DESC LIMIT 200");$db->bind(':owner',$owner);$items=$db->fetchAll();$messages=[];
 if($selected){$db->query("SELECT m.* FROM ai_agent_conversation_messages m JOIN ai_agent_conversations c ON c.id=m.id_conversation WHERE m.id_conversation=:id AND c.id_owner=:owner ORDER BY m.id");$db->bind(':id',$selected);$db->bind(':owner',$owner);$messages=$db->fetchAll();}
 return TemplateResponse::render(__DIR__.'/index.twig',['items'=>$items,'messages'=>$messages,'selected'=>$selected]);
});$router->run();
