<?php

namespace App\Repositories;

class CrmLeadRepository extends BaseRepository
{
    protected array $fields = ['id_user', 'name', 'email', 'phone', 'address', 'id_status', 'id_category', 'id_owner', 'archived', 'languaje', 'comments'];

    public function __construct()
    {
        $this->table = "crm_leads";
        $this->db = new Connection();
    }

    public function getByLeadId(int $leadId): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_lead = :id_lead ORDER BY created_at DESC");
        $this->db->bind(":id_lead", $leadId);
        return $this->db->fetchAll();
    }

    public function getAllByInstitutionOwner(int $institutionOwnerId): array
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner ORDER BY created_at DESC");
        $this->db->bind(":id_owner", $institutionOwnerId);
        return $this->db->fetchAll();
    }

    public function paginateAndFilterByInstitutionOwner(int $institutionOwnerId, array $filters = [], int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;
        $conditions = ["`id_owner` = :id_owner"];
        $params = ["id_owner" => $institutionOwnerId];

        foreach ($filters as $field => $value) {
            if (!in_array($field, $this->fields)) {
                continue;
            }

            $paramKey = ":filter_$field";
            if (is_bool($value) || is_int($value)) {
                $conditions[] = "`$field` = $paramKey";
                $params["filter_$field"] = $value;
            } else {
                $conditions[] = "`$field` LIKE $paramKey";
                $params["filter_$field"] = "%$value%";
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        $this->db->query("SELECT * FROM `{$this->table}` $whereClause LIMIT :limit OFFSET :offset");

        foreach ($params as $key => $value) {
            $this->db->bind(":$key", $value);
        }

        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        $this->db->bind(':offset', $offset, \PDO::PARAM_INT);

        $data = $this->db->fetchAll();

        $this->db->query("SELECT COUNT(*) as total FROM `{$this->table}` $whereClause");
        foreach ($params as $key => $value) {
            $this->db->bind(":$key", $value);
        }

        $totalResult = $this->db->fetchOne();

        return [
            'data' => $data,
            'current_page' => $page,
            'limit' => $limit,
            'total' => (int)$totalResult->total,
            'last_page' => ceil($totalResult->total / $limit),
        ];
    }

    public function getOneByIdAndOwner(int $id, int $institutionOwnerId): ?object
    {
        $this->db->query("SELECT * FROM `{$this->table}` WHERE `id` = :id AND `id_owner` = :id_owner");
        $this->db->bind(":id", $id);
        $this->db->bind(":id_owner", $institutionOwnerId);
        
        $result = $this->db->fetchOne();
        return !$result ? null : $result;
    }

    public function getOneWithoutOwnership(array $conditions): ?object
    {
        if (empty($conditions)) {
            return null;
        }

        $whereParts = [];
        foreach ($conditions as $field => $value) {
            $whereParts[] = "`{$field}` = :{$field}";
        }

        $sql = "SELECT * FROM `{$this->table}` WHERE " . implode(" AND ", $whereParts) . " LIMIT 1";
        $this->db->query($sql);

        foreach ($conditions as $field => $value) {
            $this->db->bind(":{$field}", $value);
        }

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function addWithExplicitOwner(array $data): bool
    {
        try {
            $keys = array_keys($data);
            $columns = array_map(fn($key) => "`{$key}`", $keys);
            $placeholders = array_map(fn($key) => ":{$key}", $keys);

            $sql = "INSERT INTO `{$this->table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

            $this->db->query($sql);

            foreach ($data as $key => $value) {
                $this->db->bind(":{$key}", $value);
            }

            return (bool) $this->db->execute();
        } catch (\PDOException $th) {
            error_log("Error in addWithExplicitOwner: " . $th->getMessage());
            return false;
        }
    }
}
