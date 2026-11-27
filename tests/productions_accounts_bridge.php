<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/ProductionsAccountsBridge.php';

use App\Services\ProductionsAccountsBridge;

function bridgeCheck(bool $ok,string $label): void { if (!$ok) { throw new RuntimeException($label); } }
function bridgeDenied(callable $action): void {
    try { $action(); } catch (DomainException $e) { return; }
    throw new RuntimeException('Expected a denied operation.');
}
$actor=['site_key'=>'vnvevents','owner_id'=>20,'user_id'=>20,'level'=>1,'managers'=>[20=>'Portfolio lead',21=>'Example coordinator'],
    'capabilities'=>['accounts.view','accounts.create','accounts.invite','accounts.lifecycle','accounts.reset','onboarding.view']];
$bridge=new ProductionsAccountsBridge();
$root=getenv('VNV_OPS_TEST_ROOT')?:'';
putenv('VNV_OPS_PRIVATE_ROOT');putenv('VNV_OPS_MANAGED_DIR');
bridgeCheck(!$bridge->view($actor,'accounts',[])['available'],'Missing private release fails closed.');
$invalid=['capabilities'=>[]]+$actor;bridgeDenied(fn()=>$bridge->view($invalid,'accounts',[]));
if (!$root) { echo "Bridge fail-closed checks passed. Set VNV_OPS_TEST_ROOT for paired integration.\n";exit; }
require $root.'/src/ManagedWorkspaces.php';
$folder=sys_get_temp_dir().'/pmc-bridge-'.bin2hex(random_bytes(8));mkdir($folder,0700);
putenv('VNV_OPS_PRIVATE_ROOT='.$root);putenv('VNV_OPS_MANAGED_DIR='.$folder);
putenv('VNV_OPS_ACCESS_READY=0');
try {
    ManagedWorkspaces::install($folder);
    $data=['environment'=>'demo','name'=>'Example org','organization_type'=>'Nonprofit','manager_id'=>21,'admin_name'=>'Example Admin','admin_email'=>'test@example.invalid','demo_expiration'=>date('Y-m-d',time()+604800),'modules'=>['tasks']];
    bridgeDenied(fn()=>$bridge->act($actor,'account.create',['manager_id'=>939]+$data));
    $created=$bridge->act($actor,'account.create',$data);
    $id=$created['account_id'];
    $result=$bridge->view($actor,'demo',['account_id'=>$id]);
    bridgeCheck($result['available'] && count($result['accounts'])===1 && $result['account']['admin_email']===$data['admin_email'],'Selected organization details.');
    bridgeDenied(fn()=>$bridge->act($actor,'account.invite',['account_id'=>$id]));
    bridgeCheck($result['account']['access_url']===null,'External sharing gate');
    $_ENV['VNV_OPS_ACCESS_READY']='1';
    $invite=$bridge->act($actor,'account.invite',['account_id'=>$id]);
    bridgeCheck(strlen($invite['invitation_code'])===64 && !str_contains(json_encode($result),$invite['invitation_code']),'Separate code from copyable information.');
    $coordinator=['user_id'=>21,'level'=>4,'capabilities'=>['accounts.view','accounts.request','onboarding.view'],'managers'=>[21=>'Example coordinator']]+$actor;
    $other=['user_id'=>23]+$coordinator;
    bridgeDenied(fn()=>$bridge->view($other,'accounts',['account_id'=>$id]));
    bridgeDenied(fn()=>$bridge->act($coordinator,'account.activate',['account_id'=>$id]));
    $request=['account_id'=>$id,'requested_change'=>'activate','reason'=>'Signed operating scope','next_action'=>'Direction reviews the signed scope'];
    $bridge->act($coordinator,'account.request',$request);
    bridgeDenied(fn()=>$bridge->act($coordinator,'account.request',$request));
    bridgeCheck($bridge->summary($actor)['pending_requests']===1 && $bridge->summary($actor)['demo']===1,'Real management counters and request');
    $bridge->act($actor,'account.decline',['account_id'=>$id,'reason'=>'Confirm scope dates']);
    bridgeCheck($bridge->summary($actor)['pending_requests']===0,'Direction decision resolves request');
    $report=$bridge->view($coordinator,'onboarding',['account_id'=>$id,'print'=>'1','print_member'=>'1','print_level'=>'2']);
    bridgeCheck($report['print_mode'] && $report['training']['organization_id']===$id && $report['print_level']===2,'Authorized training by organization.');
    echo "Paired VNV accounts bridge passed.\n";
} finally {
    foreach (glob($folder.'/*')?:[] as $file) { unlink($file); }
    rmdir($folder);
}

