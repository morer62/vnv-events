<?php
require_once dirname(__DIR__,2).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
use App\Repositories\Connection;
use App\Services\LoyaltyRewardsService;
$db=new Connection();$db->query("SELECT id_owner,site_key FROM loyalty_settings WHERE status='ACTIVE'");$scopes=$db->fetchAll();$released=0;$service=new LoyaltyRewardsService();foreach($scopes as $scope)$released+=$service->releaseDue((int)$scope->id_owner,(string)$scope->site_key);echo '['.date(DATE_ATOM)."] released={$released}\n";
