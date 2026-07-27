<?php
namespace App\Services;

use App\Repositories\Connection;

final class AiAgentNotificationService
{
    public function approvalReady(int $userId,int $approvalId,string $title): void
    {
        $message=mb_substr('AI approval ready: '.$title,0,120);$link='/panel/agents/approval?id='.$approvalId;$db=new Connection();
        $db->query("INSERT INTO notifications(id_user,mensaje,link,leido,timestamp) VALUES(:user,:message,:link,'NO',NOW())");
        $db->bind(':user',$userId);$db->bind(':message',$message);$db->bind(':link',$link);$db->execute();
        if(filter_var($_ENV['AI_AGENT_NOTIFY_EMAIL']??false,FILTER_VALIDATE_BOOL)){
            $db->query("SELECT email FROM users WHERE id=:id");$db->bind(':id',$userId);$user=$db->fetchOne();
            if($user&&filter_var($user->email,FILTER_VALIDATE_EMAIL))try{(new EmailService())->sendSimpleEmail($user->email,'VNV Events — AI approval ready','<p>'.htmlspecialchars($message).'</p><p>Open your VNV Events dashboard to review it.</p>');}catch(\Throwable){}
        }
        if(filter_var($_ENV['AI_AGENT_NOTIFY_PUSH']??false,FILTER_VALIDATE_BOOL))try{NotificationService::sendToUsers([$userId],'VNV Events',$message,['url'=>$link,'approval_id'=>$approvalId]);}catch(\Throwable){}
    }
}
