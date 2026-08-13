<?php
namespace App\Repositories;

final class LeadIntakeRepository
{
    public Connection $db;
    public function __construct(){ $this->db=new Connection(); }
    public function all(int $owner): array{$this->db->query("SELECT li.*,CONCAT(u.name,' ',u.lastname) manager_name FROM lead_intake li LEFT JOIN users u ON u.id=li.suggested_manager_id WHERE li.id_owner=:owner ORDER BY li.created_at DESC LIMIT 200");$this->db->bind(':owner',$owner);return $this->db->fetchAll();}
    public function find(int $owner,int $id): ?object{$this->db->query("SELECT * FROM lead_intake WHERE id_owner=:owner AND id=:id");$this->db->bind(':owner',$owner);$this->db->bind(':id',$id);return $this->db->fetchOne()?:null;}
    public function upsert(int $owner,array $d): int
    {
        $external=trim((string)($d['external_id']??''))?:null;
        if($external){$this->db->query("SELECT id FROM lead_intake WHERE id_owner=:owner AND source=:source AND external_id=:external LIMIT 1");$this->db->bind(':owner',$owner);$this->db->bind(':source',$d['source']);$this->db->bind(':external',$external);$row=$this->db->fetchOne();if($row){$id=(int)$row->id;$this->update($owner,$id,$d);return $id;}}
        $fields=['source','external_id','channel','contact_name','email','phone','service_requested','guest_count','venue','event_date','start_time','end_time','setup_minutes','availability_status','suggested_manager_id','availability_checked_at','status','payload_json'];
        $this->db->query("INSERT INTO lead_intake(id_owner,".implode(',',$fields).") VALUES(:owner,".implode(',',array_map(fn($f)=>':'.$f,$fields)).")");$this->db->bind(':owner',$owner);foreach($fields as $f)$this->db->bind(':'.$f,$d[$f]??null);$this->db->execute();return (int)$this->db->lastId();
    }
    public function update(int $owner,int $id,array $d): void{$allowed=['contact_name','email','phone','service_requested','guest_count','venue','event_date','start_time','end_time','setup_minutes','availability_status','suggested_manager_id','availability_checked_at','status','payload_json','converted_order_id'];$set=[];foreach($allowed as $f)if(array_key_exists($f,$d))$set[]="{$f}=:{$f}";if(!$set)return;$this->db->query("UPDATE lead_intake SET ".implode(',',$set)." WHERE id_owner=:owner AND id=:id");foreach($allowed as $f)if(array_key_exists($f,$d))$this->db->bind(':'.$f,$d[$f]);$this->db->bind(':owner',$owner);$this->db->bind(':id',$id);$this->db->execute();}
}
