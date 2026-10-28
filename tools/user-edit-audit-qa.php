<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/db/20261028_user_edit_logs.sql');
$service=(string)file_get_contents($root.'/src/Services/UserEditService.php');

if(!str_contains($migration,'CREATE TABLE IF NOT EXISTS `user_edit_logs`'))throw new RuntimeException('The user_edit_logs migration is missing.');
foreach(['`user_id`','`edited_by`','`changes`','`created_at`'] as $column)if(!str_contains($migration,$column))throw new RuntimeException('Missing audit column '.$column);
if(!str_contains($service,"error_log('[User edit audit] '"))throw new RuntimeException('User edits are not protected from a post-save audit infrastructure error.');

require_once $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$db=new App\Repositories\Connection();
$db->beginTransaction();
try{
    $db->query('INSERT INTO user_edit_logs(user_id,edited_by,changes) VALUES(:user,:editor,:changes)');
    $db->bind(':user',0);$db->bind(':editor',0);$db->bind(':changes',json_encode(['qa'=>true,'rolled_back'=>true]));$db->execute();
    if($db->rowCount()!==1)throw new RuntimeException('The audit insert did not affect exactly one row.');
}finally{
    $db->rollback();
}

fwrite(STDOUT,"PASS: the user-edit audit table accepts a real transactional insert (rolled back) and a post-save audit failure no longer renders a false edit failure.\n");
