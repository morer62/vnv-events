<?php
declare(strict_types=1);
namespace App\Services;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

/** Authenticated controller supplies an owner-scoped actor and eligible managers on every call. */
final class ProductionsAccountsBridge
{
    private function setting(string $name): string {
        $value=$_ENV[$name]??getenv($name);
        return is_string($value)?$value:'';
    }
    private function registry(): \ManagedWorkspaces {
        $release=$this->setting('VNV_OPS_PRIVATE_ROOT');
        $dir=$this->setting('VNV_OPS_MANAGED_DIR');
        $library=$release!==''?realpath($release.'/src/ManagedWorkspaces.php'):false;
        if (!$library || !is_file($library) || !$dir) { throw new RuntimeException('Private VNV-Q storage is unavailable.'); }
        require_once $library;
        return \ManagedWorkspaces::open($dir);
    }
    public function summary(array $actor): array {
        $this->assertAccess($actor,'accounts.view');
        try { return $this->registry()->summary($actor); }
        catch (\Throwable $e) { return ['available'=>false]; }
    }
    private function assertAccess(array $actor,string $cap): void {
        if (!in_array((int)($actor['level']??0),[1,4],true) || (int)($actor['owner_id']??0)<1 || (int)($actor['user_id']??0)<1 || !in_array($cap,$actor['capabilities']??[],true)) { throw new DomainException('This workspace is outside your permissions.'); }
    }
    public function view(array $actor,string $tab,array $query): array {
        $permission=$tab==='onboarding'?'onboarding.view':'accounts.view';
        $this->assertAccess($actor,$permission);
        $result=['available'=>false,'accounts'=>[],'account'=>null,'training'=>null,'modules'=>[],'managers'=>$actor['managers']??[], 'caps'=>array_fill_keys($actor['capabilities'],true)];
        $result['access_ready']=$this->setting('VNV_OPS_ACCESS_READY')==='1';
        $result['print_mode']=$tab==='onboarding' && ($query['print']??null)==='1';
        $result['print_member']=filter_var($query['print_member']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
        $level=filter_var($query['print_level']??0,FILTER_VALIDATE_INT);
        $result['print_level']=in_array($level,[2,4,5],true)?$level:0;
        try {
            $registry=$this->registry();
            $result['accounts']=$registry->listing($actor,$permission);
            if (!$result['access_ready']) {
                foreach ($result['accounts'] as &$listed) { $listed['access_url']=null; }
                unset($listed);
            }
            if ($tab==='demo') { $result['accounts']=array_values(array_filter($result['accounts'],fn(array $row): bool=>$row['environment']==='demo')); }
            $accountId=filter_var($query['account_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($accountId) {
                $result['account']=$registry->detail($actor,$accountId,$permission);
                $result['training']=$result['account']['training']??null;
                if (!$result['access_ready']) {
                    $result['account']['access_url']=null;
                    if ($result['training']!==null) { $result['training']['url']=null; $result['account']['training']['url']=null; }
                }
            }
            $result['modules']=\ManagedWorkspaces::MODULES;
            $result['available']=true;
        } catch (\PilotAccessDenied $e) { throw new DomainException('Account unavailable.'); }
        catch (\Throwable $e) { $result['notice']='The private VNV-Q registry is unavailable. Contact your workspace administrator.'; }
        return $result;
    }
    public function act(array $actor,string $action,array $post): array {
        $perms=['account.create'=>'accounts.create','account.invite'=>'accounts.invite','account.reset'=>'accounts.reset','account.request'=>'accounts.request','account.decline'=>'accounts.lifecycle',
            'account.activate'=>'accounts.lifecycle','account.suspend'=>'accounts.lifecycle','account.reactivate'=>'accounts.lifecycle','account.deactivate'=>'accounts.lifecycle'];
        $this->assertAccess($actor,$perms[$action]??throw new DomainException('Unknown account action.'));
        try {
            $registry=$this->registry();
            if ($action==='account.create') {
                $manager=filter_var($post['manager_id']??null,FILTER_VALIDATE_INT);
                if (!$manager || !isset($actor['managers'][$manager])) { throw new DomainException('Choose an eligible manager from your organization.'); }
                return ['account_id'=>$registry->create($actor,$post),'message'=>'Organization created. Review access details below.'];
            }
            $id=filter_var($post['account_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) { throw new DomainException('Choose an organization.'); }
            if ($action==='account.invite') {
                if ($this->setting('VNV_OPS_ACCESS_READY')!=='1') { throw new DomainException('External invitations will be available after the workspace domain and trusted TLS are verified.'); }
                return ['account_id'=>$id,'message'=>'Share this 24-hour single-use invitation privately with the administrator.', 'invitation_code'=>$registry->invite($actor,$id)];
            }
            if ($action==='account.request') { $registry->requestChange($actor,$id,$post); return ['account_id'=>$id,'message'=>'Request recorded for direction.']; }
            if ($action==='account.decline') { $registry->declineRequest($actor,$id,$post); return ['account_id'=>$id,'message'=>'Request declined.']; }
            $registry->change($actor,$id,substr($action,8),$post);
            return ['account_id'=>$id,'message'=>'Account updated.'];
        } catch (\PilotAccessDenied $e) { throw new DomainException('This organization or action is outside your permissions.'); }
        catch (InvalidArgumentException $e) { throw new DomainException($e->getMessage()); }
        catch (DomainException $e) { throw $e; }
        catch (\Throwable $e) { throw new DomainException('The private VNV-Q registry is unavailable. Your workspace administrator can check the configured release.'); }
    }
}

