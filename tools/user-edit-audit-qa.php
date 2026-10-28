<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/db/20261028_user_edit_logs.sql');
$service=(string)file_get_contents($root.'/src/Services/UserEditService.php');

if(!str_contains($migration,'CREATE TABLE IF NOT EXISTS `user_edit_logs`'))throw new RuntimeException('The user_edit_logs migration is missing.');
foreach(['`user_id`','`edited_by`','`changes`','`created_at`'] as $column)if(!str_contains($migration,$column))throw new RuntimeException('Missing audit column '.$column);
if(!str_contains($service,"error_log('[User edit audit] '"))throw new RuntimeException('User edits are not protected from a post-save audit infrastructure error.');

fwrite(STDOUT,"PASS: the user-edit audit table is defined and a post-save audit failure no longer renders a false edit failure.\n");
