<?php

namespace App\Repositories;

use App\Utils\SiteContext;
use RuntimeException;

final class AiAgentConnectionsRepository
{
    private Connection $db;
    public function __construct(){ $this->db=new Connection(); }

    public function all(int $ownerId,int $agentId): array
    {
        $this->db->query("SELECT id,platform,account_label,account_identifier,credential_hint,status,last_error,verified_at,updated_at FROM ai_agent_connections WHERE id_owner=:owner AND site_key=:site AND id_agent=:agent ORDER BY platform");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->bind(':agent',$agentId);return $this->db->fetchAll();
    }

    public function save(int $ownerId,int $agentId,string $platform,string $label,string $identifier,string $token,array $extra=[]): void
    {
        if(!in_array($platform,['facebook','instagram','linkedin','youtube','whatsapp'],true))throw new RuntimeException('Unsupported social platform.');
        $existing=$this->findEncrypted($ownerId,$agentId,$platform);
        if($token===''&&!$existing)throw new RuntimeException('Enter an access token for '.$platform.'.');
        if($token!==''||$extra){
            $current=$existing?$this->decrypt((string)$existing->credentials_encrypted):[];
            $encrypted=$this->encrypt(array_merge($current,$extra,$token!==''?['access_token'=>$token]:[]));
        }else $encrypted=(string)$existing->credentials_encrypted;
        $hint=$token!==''?substr($token,-6):(string)$existing->credential_hint;
        $this->db->query("INSERT INTO ai_agent_connections(id_agent,id_owner,site_key,platform,account_label,account_identifier,credentials_encrypted,credential_hint,status)
            VALUES(:agent,:owner,:site,:platform,:label,:identifier,:credentials,:hint,'CONFIGURED')
            ON DUPLICATE KEY UPDATE account_label=VALUES(account_label),account_identifier=VALUES(account_identifier),
            credentials_encrypted=VALUES(credentials_encrypted),credential_hint=VALUES(credential_hint),status='CONFIGURED',last_error=NULL,verified_at=NULL");
        foreach(['agent'=>$agentId,'owner'=>$ownerId,'site'=>SiteContext::siteKey(),'platform'=>$platform,'label'=>$label?:null,'identifier'=>$identifier?:null,'credentials'=>$encrypted,'hint'=>$hint] as $key=>$value)$this->db->bind(':'.$key,$value);
        $this->db->execute();
    }

    public function credentials(int $ownerId,int $agentId,string $platform): array
    {
        $row=$this->findEncrypted($ownerId,$agentId,$platform);
        if(!$row||$row->status==='DISCONNECTED'||trim((string)$row->credentials_encrypted)==='')throw new RuntimeException(ucfirst($platform).' is not connected.');
        $credentials=$this->decrypt((string)$row->credentials_encrypted);
        $credentials['account_identifier']=(string)$row->account_identifier;
        $credentials['connection_id']=(int)$row->id;
        return $credentials;
    }

    public function verificationResult(int $connectionId,bool $ok,?string $error=null): void
    {
        $this->db->query("UPDATE ai_agent_connections SET status=:status,last_error=:error,verified_at=".($ok?'NOW()':'NULL')." WHERE id=:id");
        $this->db->bind(':status',$ok?'VERIFIED':'ERROR');$this->db->bind(':error',$error);$this->db->bind(':id',$connectionId);$this->db->execute();
    }

    public function disconnect(int $ownerId,int $agentId,string $platform): void
    {
        $this->db->query("UPDATE ai_agent_connections SET status='DISCONNECTED',credentials_encrypted='',credential_hint=NULL WHERE id_owner=:owner AND site_key=:site AND id_agent=:agent AND platform=:platform");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->bind(':agent',$agentId);$this->db->bind(':platform',$platform);$this->db->execute();
    }

    private function findEncrypted(int $ownerId,int $agentId,string $platform): ?object
    {
        $this->db->query("SELECT * FROM ai_agent_connections WHERE id_owner=:owner AND site_key=:site AND id_agent=:agent AND platform=:platform LIMIT 1");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->bind(':agent',$agentId);$this->db->bind(':platform',$platform);return $this->db->fetchOne()?:null;
    }

    private function encrypt(array $value): string
    {
        $secret=trim((string)($_ENV['VNV_SECRET_KEY']??$_ENV['PAYMENT_ENCRYPTION_KEY']??''));
        if($secret==='')throw new RuntimeException('VNV_SECRET_KEY is required to encrypt social credentials.');
        $key=hash('sha256',$secret,true);$iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt(json_encode($value,JSON_UNESCAPED_SLASHES),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new RuntimeException('Unable to encrypt social credentials.');
        return base64_encode(json_encode(['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)],JSON_UNESCAPED_SLASHES));
    }

    private function decrypt(string $value): array
    {
        $secret=trim((string)($_ENV['VNV_SECRET_KEY']??$_ENV['PAYMENT_ENCRYPTION_KEY']??''));
        if($secret==='')throw new RuntimeException('VNV_SECRET_KEY is required to decrypt social credentials.');
        $envelope=json_decode((string)base64_decode($value,true),true);
        if(!is_array($envelope))throw new RuntimeException('The stored social credential is invalid.');
        $plain=openssl_decrypt(base64_decode((string)($envelope['data']??'')),'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,base64_decode((string)($envelope['iv']??'')),base64_decode((string)($envelope['tag']??'')));
        $decoded=json_decode((string)$plain,true);
        if(!is_array($decoded))throw new RuntimeException('The stored social credential could not be decrypted.');
        return $decoded;
    }
}
