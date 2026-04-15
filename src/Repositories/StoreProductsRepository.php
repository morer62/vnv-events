<?php

namespace App\Repositories;

class StoreProductsRepository extends BaseRepository
{
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';
    const STATUS_DRAFT = 'DRAFT';

    protected array $fields = [
        'id',
        'id_owner',
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'price',
        'promo_price',
        'main_image',
        'gallery',
        'stock_quantity',
        'min_purchase_qty',
        'max_purchase_qty',
        'is_featured',
        'is_public',
        'status',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_products";
        $this->db = new Connection();
        $this->ensureSlugColumn();
    }

    public function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value)));
        $slug = trim($slug, '-');
        return $slug;
    }

    public function generateUniqueSlug(string $baseValue, int $excludeId = 0): string
    {
        $slug = $this->normalizeSlug($baseValue);
        if ($slug === '') {
            $slug = 'product';
        }

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug, int $excludeId = 0): bool
    {
        $query = "SELECT id FROM {$this->table} WHERE slug = :slug";
        if ($excludeId > 0) {
            $query .= " AND id != :exclude_id";
        }
        $query .= " LIMIT 1";
        $this->db->query($query);
        $this->db->bind(':slug', $slug);
        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }
        return $this->db->fetchOne() !== false;
    }

    public function skuExists(string $sku, int $excludeId = 0): bool
    {
        $query = "SELECT id FROM {$this->table} WHERE sku = :sku";
        if ($excludeId > 0) {
            $query .= " AND id != :exclude_id";
        }
        $query .= " LIMIT 1";

        $this->db->query($query);
        $this->db->bind(':sku', $sku);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        return $this->db->fetchOne() !== false;
    }

    public function getBySlug(string $slug): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $this->db->bind(':slug', $slug);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublicBySlug(string $slug): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE slug = :slug
              AND is_public = 1
              AND status = :status
            LIMIT 1
        ");
        $this->db->bind(':slug', $slug);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getPublicProducts(): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE is_public = 1
              AND status = :status
            ORDER BY is_featured DESC, created_at DESC
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        return $this->db->fetchAll();
    }

    public function getPublicByCategory(int $categoryId, int $limit = 48): array
    {
        $this->db->query("
            SELECT p.*
            FROM {$this->table} p
            INNER JOIN store_products_categories pc ON pc.id_product = p.id
            WHERE pc.id_category = :id_category
              AND p.is_public = 1
              AND p.status = :status
            ORDER BY p.is_featured DESC, p.created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_category', $categoryId, \PDO::PARAM_INT);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll() ?: [];
    }

    public function getPublicRelatedProducts(int $excludeProductId, int $limit = 6): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id != :id
              AND is_public = 1
              AND status = :status
            ORDER BY is_featured DESC, created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':id', $excludeProductId, \PDO::PARAM_INT);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        return $this->db->fetchAll() ?: [];
    }

    public function getFeaturedProducts(int $limit = 8): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE is_public = 1
              AND is_featured = 1
              AND status = :status
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }

    public function getAvailableStock(int $productId): int
    {
        $product = $this->getOne(['id' => $productId], ['stock_quantity']);
        return $product ? (int)$product->stock_quantity : 0;
    }

    public function hasStock(int $productId, int $qty = 1): bool
    {
        return $this->getAvailableStock($productId) >= $qty;
    }

    public function updateStock(int $productId, int $newQty): bool
    {
        return $this->update([
            'stock_quantity' => $newQty,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $productId
        ]);
    }

    public function decreaseStock(int $productId, int $qty): bool
    {
        $product = $this->getOne(['id' => $productId]);

        if (!$product) {
            return false;
        }

        $currentStock = (int)$product->stock_quantity;
        if ($currentStock < $qty) {
            return false;
        }

        $newStock = $currentStock - $qty;

        return $this->update([
            'stock_quantity' => $newStock,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $productId
        ]);
    }

    public function searchPublic(string $term = ''): array
    {
        $query = "
            SELECT *
            FROM {$this->table}
            WHERE is_public = 1
              AND status = :status
        ";

        if (!empty($term)) {
            $query .= " AND (
                name LIKE :term
                OR short_description LIKE :term
                OR description LIKE :term
                OR sku LIKE :term
            )";
        }

        $query .= " ORDER BY is_featured DESC, created_at DESC";

        $this->db->query($query);
        $this->db->bind(':status', self::STATUS_ACTIVE);

        if (!empty($term)) {
            $this->db->bind(':term', '%' . $term . '%');
        }

        return $this->db->fetchAll();
    }


    public function getPublicActiveProducts(int $limit = 50): array
{
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE status = :status
          AND is_public = 1
        ORDER BY is_featured DESC, id DESC
        LIMIT :limit
    ");
    $this->db->bind(':status', 'ACTIVE');
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll() ?: [];
}

public function getPublicById(int $id): ?object
{
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE id = :id
          AND status = :status
          AND is_public = 1
        LIMIT 1
    ");
    $this->db->bind(':id', $id);
    $this->db->bind(':status', self::STATUS_ACTIVE);
    $row = $this->db->fetchOne();
    return $row ?: null;
}

private function ensureSlugColumn(): void
{
    if (!$this->hasColumn('slug')) {
        $this->db->query("ALTER TABLE {$this->table} ADD COLUMN slug VARCHAR(255) NULL AFTER name");
        $this->db->execute();
    }
}

private function hasColumn(string $column): bool
{
    $this->db->query("SHOW COLUMNS FROM {$this->table} LIKE :column_name");
    $this->db->bind(':column_name', $column);
    return $this->db->fetchOne() !== false;
}

public function getRecommendedProducts(string $audience, string $mealStyle, int $limit = 24): array
{
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE status = :status
          AND is_public = 1
          AND (
                audiences LIKE :audience_exact
                OR audiences LIKE :audience_start
                OR audiences LIKE :audience_middle
                OR audiences LIKE :audience_end
              )
          AND (
                meal_styles LIKE :style_exact
                OR meal_styles LIKE :style_start
                OR meal_styles LIKE :style_middle
                OR meal_styles LIKE :style_end
              )
        ORDER BY is_featured DESC, id DESC
        LIMIT :limit
    ");

    $this->db->bind(':status', 'ACTIVE');

    $this->db->bind(':audience_exact', $audience);
    $this->db->bind(':audience_start', $audience . ',%');
    $this->db->bind(':audience_middle', '%,' . $audience . ',%');
    $this->db->bind(':audience_end', '%,' . $audience);

    $this->db->bind(':style_exact', $mealStyle);
    $this->db->bind(':style_start', $mealStyle . ',%');
    $this->db->bind(':style_middle', '%,' . $mealStyle . ',%');
    $this->db->bind(':style_end', '%,' . $mealStyle);

    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    $results = $this->db->fetchAll() ?: [];

    if (!empty($results)) {
        return $results;
    }

    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE status = :status
          AND is_public = 1
          AND (
                audiences LIKE :audience_exact
                OR audiences LIKE :audience_start
                OR audiences LIKE :audience_middle
                OR audiences LIKE :audience_end
                OR meal_styles LIKE :style_exact
                OR meal_styles LIKE :style_start
                OR meal_styles LIKE :style_middle
                OR meal_styles LIKE :style_end
              )
        ORDER BY is_featured DESC, id DESC
        LIMIT :limit
    ");

    $this->db->bind(':status', 'ACTIVE');

    $this->db->bind(':audience_exact', $audience);
    $this->db->bind(':audience_start', $audience . ',%');
    $this->db->bind(':audience_middle', '%,' . $audience . ',%');
    $this->db->bind(':audience_end', '%,' . $audience);

    $this->db->bind(':style_exact', $mealStyle);
    $this->db->bind(':style_start', $mealStyle . ',%');
    $this->db->bind(':style_middle', '%,' . $mealStyle . ',%');
    $this->db->bind(':style_end', '%,' . $mealStyle);

    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll() ?: [];
}

public function countPublicProductsByAudience(string $audience): int
{
    $this->db->query("
        SELECT COUNT(*) AS total
        FROM {$this->table}
        WHERE status = :status
          AND is_public = 1
          AND (
                audiences LIKE :audience_exact
                OR audiences LIKE :audience_start
                OR audiences LIKE :audience_middle
                OR audiences LIKE :audience_end
              )
    ");

    $this->db->bind(':status', 'ACTIVE');
    $this->db->bind(':audience_exact', $audience);
    $this->db->bind(':audience_start', $audience . ',%');
    $this->db->bind(':audience_middle', '%,' . $audience . ',%');
    $this->db->bind(':audience_end', '%,' . $audience);

    $result = $this->db->fetchOne();

    return (int)($result->total ?? 0);
}

public function getPublicFeaturedByAudience(string $audience, int $limit = 4): array
{
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE status = :status
          AND is_public = 1
          AND (
                audiences LIKE :audience_exact
                OR audiences LIKE :audience_start
                OR audiences LIKE :audience_middle
                OR audiences LIKE :audience_end
              )
        ORDER BY is_featured DESC, id DESC
        LIMIT :limit
    ");

    $this->db->bind(':status', 'ACTIVE');
    $this->db->bind(':audience_exact', $audience);
    $this->db->bind(':audience_start', $audience . ',%');
    $this->db->bind(':audience_middle', '%,' . $audience . ',%');
    $this->db->bind(':audience_end', '%,' . $audience);
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll() ?: [];
}



public function getPublicProductsByMealStyle(string $mealStyle, int $limit = 24): array
{
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE status = :status
          AND is_public = 1
          AND (
                meal_styles LIKE :style_exact
                OR meal_styles LIKE :style_start
                OR meal_styles LIKE :style_middle
                OR meal_styles LIKE :style_end
              )
        ORDER BY is_featured DESC, id DESC
        LIMIT :limit
    ");

    $this->db->bind(':status', 'ACTIVE');
    $this->db->bind(':style_exact', $mealStyle);
    $this->db->bind(':style_start', $mealStyle . ',%');
    $this->db->bind(':style_middle', '%,' . $mealStyle . ',%');
    $this->db->bind(':style_end', '%,' . $mealStyle);
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll() ?: [];
}


    public function getFullProductDetails(int $productId): ?object
{
    try {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $productId);
        $product = $this->db->fetchOne();

        if (!$product) {
            return null;
        }

        $categoriesRepo = new StoreProductsCategoriesRepository();
        $attributesRepo = new StoreProductsAttributesRepository();
        $nutritionRepo = new StoreProductsNutritionRepository();

        $product->categories = $categoriesRepo->getCategoriesByProduct($productId);
        $product->attributes = $attributesRepo->getByProduct($productId);
        $product->attributes_grouped = $attributesRepo->getGroupedByProduct($productId);
        $product->nutrition = $nutritionRepo->getByProduct($productId);

        return $product;
    } catch (\PDOException $e) {
        if ($this->showError) {
            echo $e->getMessage();
        }
        return null;
    }
}
}