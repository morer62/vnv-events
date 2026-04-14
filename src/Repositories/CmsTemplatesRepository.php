<?php

namespace App\Repositories;

class CmsTemplatesRepository extends BaseRepository
{
    protected string $table = "cms_templates";

    protected array $fields = [
        'id',
        'id_owner',
        'name',
        'template_key',
        'description',
        'type',
        'preview_html',
        'template_structure_json',
        'status',
        'created_at',
        'updated_at',
    ];

    public function getActive(): array
    {
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `status` = 'ACTIVE'
            ORDER BY `name` ASC
        ");

        return $this->db->fetchAll() ?: [];
    }

    public function getByTemplateKey(string $templateKey): ?object
    {
        return $this->getOne([
            'template_key' => $templateKey
        ]);
    }

    public function templateKeyExists(string $templateKey, int $excludeId = 0): bool
    {
        $query = "
            SELECT COUNT(*) as total
            FROM `{$this->table}`
            WHERE `template_key` = :template_key
        ";

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':template_key', $templateKey);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }


    public function getAllForPanel(): array
    {
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            ORDER BY `created_at` DESC, `id` DESC
        ");

        return $this->db->fetchAll() ?: [];
    }

    public function getByType(string $type): array
    {
        $this->db->query("
            SELECT *
            FROM `{$this->table}`
            WHERE `type` = :type
            AND `status` = 'ACTIVE'
            ORDER BY `name` ASC
        ");

        $this->db->bind(':type', $type);

        return $this->db->fetchAll() ?: [];
    }
}