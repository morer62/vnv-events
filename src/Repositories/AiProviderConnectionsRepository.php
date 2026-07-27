<?php
namespace App\Repositories;

use RuntimeException;

final class AiProviderConnectionsRepository
{
    private Connection $db;
    public function __construct(){ $this->db=new Connection(); }
    public function all(int $ownerId): array{$this->db->query("SELECT id,provider,key_hint,text_model,image_model,enabled,is_default_text,is_default_image,updated_at FROM ai_provider_connections WHERE id_owner=:owner ORDER BY provider");$this->db->bind(':owner',$ownerId);return $this->db->fetchAll();}
    public function save(int $ownerId,array $data): void
    {
        $provider=(string)($data['provider']??'');if(!in_array($provider,['openai','anthropic','gemini'],true))throw new RuntimeException('Unsupported AI provider.');
        $existing=$this->raw($ownerId,$provider);$key=trim((string)($data['api_key']??''));if($key===''&&!$existing)throw new RuntimeException('Enter the API key.');
        $encrypted=$key!==''?$this->encrypt($key):(string)$existing->api_key_encrypted;$hint=$key!==''?substr($key,-6):(string)$existing->key_hint;
        if(!empty($data['is_default_text'])){$this->db->query("UPDATE ai_provider_connections SET is_default_text=0 WHERE id_owner=:owner");$this->db->bind(':owner',$ownerId);$this->db->execute();}
        if(!empty($data['is_default_image'])){$this->db->query("UPDATE ai_provider_connections SET is_default_image=0 WHERE id_owner=:owner");$this->db->bind(':owner',$ownerId);$this->db->execute();}
        $this->db->query("INSERT INTO ai_provider_connections(id_owner,provider,api_key_encrypted,key_hint,text_model,image_model,enabled,is_default_text,is_default_image) VALUES(:owner,:provider,:key,:hint,:text_model,:image_model,1,:default_text,:default_image)
          ON DUPLICATE KEY UPDATE api_key_encrypted=VALUES(api_key_encrypted),key_hint=VALUES(key_hint),text_model=VALUES(text_model),image_model=VALUES(image_model),enabled=1,is_default_text=VALUES(is_default_text),is_default_image=VALUES(is_default_image)");
        foreach(['owner'=>$ownerId,'provider'=>$provider,'key'=>$encrypted,'hint'=>$hint,'text_model'=>trim((string)($data['text_model']??''))?:null,'image_model'=>trim((string)($data['image_model']??''))?:null,'default_text'=>!empty($data['is_default_text'])?1:0,'default_image'=>!empty($data['is_default_image'])?1:0] as $k=>$v)$this->db->bind(':'.$k,$v);$this->db->execute();
    }
    public function credentials(int $ownerId,string $provider): ?array
    {
        $row=$this->raw($ownerId,$provider);if(!$row||!$row->enabled)return null;return ['provider'=>$provider,'api_key'=>$this->decrypt((string)$row->api_key_encrypted),'text_model'=>(string)$row->text_model,'image_model'=>(string)$row->image_model];
    }
    public function defaultProvider(int $ownerId,string $kind='text'): string
    {
        $column=$kind==='image'?'is_default_image':'is_default_text';$this->db->query("SELECT provider FROM ai_provider_connections WHERE id_owner=:owner AND enabled=1 AND {$column}=1 LIMIT 1");$this->db->bind(':owner',$ownerId);return (string)($this->db->fetchOne()->provider??'openai');
    }
    private function raw(int $ownerId,string $provider): ?object{$this->db->query("SELECT * FROM ai_provider_connections WHERE id_owner=:owner AND provider=:provider LIMIT 1");$this->db->bind(':owner',$ownerId);$this->db->bind(':provider',$provider);return $this->db->fetchOne()?:null;}
    private function cryptoKey(): string{$secret=trim((string)($_ENV['VNV_SECRET_KEY']??$_ENV['PAYMENT_ENCRYPTION_KEY']??''));if($secret==='')throw new RuntimeException('VNV_SECRET_KEY is required.');return hash('sha256',$secret,true);}
    private function encrypt(string $plain): string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);return base64_encode(json_encode(['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)],JSON_UNESCAPED_SLASHES));}
    private function decrypt(string $value): string{$data=json_decode((string)base64_decode($value,true),true);if(!$data)throw new RuntimeException('Invalid encrypted provider credential.');$plain=openssl_decrypt(base64_decode($data['data']),'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,base64_decode($data['iv']),base64_decode($data['tag']));if($plain===false)throw new RuntimeException('Unable to decrypt provider credential.');return $plain;}
}
