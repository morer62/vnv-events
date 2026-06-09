<?php

namespace App\Repositories;

use App\Repositories\Concerns\ContentOriginRepositoryTrait;
use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class CmsRoutesRepository extends BaseRepository
{
    use ContentOriginRepositoryTrait;
    use SiteScopedRepositoryTrait;

    protected string $table = "cms_routes";

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'id_content',
        'route',
        'route_hash',
        'is_main',
        'language',
        'public_php_path',
        'public_twig_path',
        'status',
        'redirect_to',
        'content_origin',
        'origin_site_key',
        'created_by',
        'updated_by',
        'origin_metadata_json',
        'created_at',
        'updated_at',
    ];

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getByRoute(string $route, string $language = 'en'): ?object
    {
        $normalizedRoute = $this->normalizeRoute($route);
        $siteSql = $this->siteScopeSql(null, 'c');

        $query = "
            SELECT r.*, c.title, c.slug, c.type, c.content_type, c.status AS content_status
            FROM `{$this->table}` r
            INNER JOIN `cms_contents` c ON c.id = r.id_content
            WHERE r.route = :route
              AND r.language = :language
              {$siteSql}
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':route', $normalizedRoute);
        $this->db->bind(':language', $language);
        $this->bindSiteScope();

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getMainRouteByContent(int $idContent, string $language = 'en'): ?object
    {
        $query = "
            SELECT *
            FROM `{$this->table}`
            WHERE id_content = :id_content
              AND language = :language
              AND is_main = 1
            LIMIT 1
        ";

        $this->db->query($query);
        $this->db->bind(':id_content', $idContent);
        $this->db->bind(':language', $language);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getAllByContent(int $idContent): array
    {
        $query = "
            SELECT *
            FROM `{$this->table}`
            WHERE id_content = :id_content
            ORDER BY is_main DESC, id ASC
        ";

        $this->db->query($query);
        $this->db->bind(':id_content', $idContent);

        return $this->db->fetchAll() ?: [];
    }

    public function routeExists(string $route, ?int $ownerId = null, string $language = 'en', int $excludeId = 0): bool
    {
        $normalizedRoute = $this->normalizeRoute($route);

        $query = "
            SELECT COUNT(*) AS total
            FROM `{$this->table}`
            WHERE `route` = :route
              AND `language` = :language
        ";

        if ($ownerId === null) {
            $query .= " AND `id_owner` IS NULL";
        } else {
            $query .= " AND (`id_owner` = :id_owner OR `id_owner` IS NULL)";
        }

        if ($excludeId > 0) {
            $query .= " AND `id` != :exclude_id";
        }

        $this->db->query($query);
        $this->db->bind(':route', $normalizedRoute);
        $this->db->bind(':language', $language);

        if ($ownerId !== null) {
            $this->db->bind(':id_owner', $ownerId);
        }

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        $result = $this->db->fetchOne();

        return $result && (int)$result->total > 0;
    }

    public function normalizeRoute(string $route): string
    {
        $route = trim($route);

        if ($route === '') {
            return '/';
        }

        $route = '/' . trim($route, '/');

        if ($route !== '/') {
            $route .= '/';
        }

        return $route;
    }
}
