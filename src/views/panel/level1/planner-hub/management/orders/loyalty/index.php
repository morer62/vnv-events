<?php
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\LoyaltyRewardsService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$findBusinessClient = static function (int $ownerId, int $clientId): ?object {
    if ($clientId <= 0) return null;
    $client = (new UserRepository())->getOneWithoutOwnership(['id' => $clientId]);
    if (!$client) return null;
    $belongsToBusiness = (int)($client->id_owner ?? 0) === $ownerId
        || (int)($client->id_owner_asociated ?? 0) === $ownerId;
    return $belongsToBusiness ? $client : null;
};
$router->get(function() use ($findBusinessClient){
    $user=LoginService::getSession();$service=new LoyaltyRewardsService();$clientId=(int)($_GET['client_id']??0);$client=null;$balance=null;$history=[];
    if($clientId>0){$client=$findBusinessClient((int)$user->getId(),$clientId);if($client){$balance=$service->balance((int)$user->getId(),$clientId);$history=$service->history((int)$user->getId(),$clientId);}else{MessageUtil::setMessage('That customer is not available for this business.','Rewards','error');}}
    return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>$service->settings((int)$user->getId()),'client'=>$client,'balance'=>$balance,'history'=>$history,'message'=>MessageUtil::getMessage()]);
});
$router->post(function() use ($findBusinessClient){
    $user=LoginService::getSession();$service=new LoyaltyRewardsService();$action=(string)($_POST['action']??'settings');
    try{
        if($action==='settings'){$service->saveSettings((int)$user->getId(),'vnvevents',$_POST,(int)$user->getId());MessageUtil::setMessage('Rewards settings updated. Historical rewards were not changed.');LocationUtils::redirectInternal('panel/planner-hub/management/orders/loyalty');}
        $clientId=(int)($_POST['client_id']??0);if(!$findBusinessClient((int)$user->getId(),$clientId))throw new RuntimeException('That customer is not available for this business.');$service->adjust((int)$user->getId(),$clientId,(float)($_POST['points']??0),(string)($_POST['reason']??''),(int)$user->getId());MessageUtil::setMessage('Reward adjustment recorded in the ledger.');LocationUtils::redirectInternal('panel/planner-hub/management/orders/loyalty?client_id='.$clientId);
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Rewards','error');LocationUtils::redirectInternal('panel/planner-hub/management/orders/loyalty'.(!empty($_POST['client_id'])?'?client_id='.(int)$_POST['client_id']:''));}
});
$router->run();
