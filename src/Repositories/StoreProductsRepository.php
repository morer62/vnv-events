<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StoreProductsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_INACTIVE = 'INACTIVE';
    const STATUS_DRAFT = 'DRAFT';

    const PRODUCT_TYPE_FIXED = 'FIXED';
    const PRODUCT_TYPE_VARIABLE = 'VARIABLE';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'product_type',
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
        $this->ensureProductTypeColumn();
    }

    private function ensureProductTypeColumn(): void
    {
        if ($this->columnExists('product_type')) {
            return;
        }

        try {
            $this->db->query("
                ALTER TABLE `{$this->table}`
                ADD COLUMN `product_type` ENUM('FIXED','VARIABLE') NOT NULL DEFAULT 'FIXED' AFTER `description`
            ");
            $this->db->execute();
        } catch (\Throwable $e) {
            // Avoid breaking runtime if DDL is restricted or migration already handled elsewhere.
        }
    }

    private function columnExists(string $column): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE :column");
            $this->db->bind(':column', $column);
            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value)));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'product';
    }

    public function generateUniqueSlug(string $baseValue, int $excludeId = 0): string
    {
        $slug = $this->normalizeSlug($baseValue);
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
        $sql = "SELECT id FROM {$this->table} WHERE slug = :slug";

        if ($excludeId > 0) {
            $sql .= " AND id != :exclude_id";
        }

        $sql .= " LIMIT 1";

        $this->db->query($sql);
        $this->db->bind(':slug', $slug);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId, \PDO::PARAM_INT);
        }

        return $this->db->fetchOne() !== false;
    }

    public function skuExists(string $sku, int $excludeId = 0): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        $sql = "SELECT id FROM {$this->table} WHERE sku = :sku";

        if ($excludeId > 0) {
            $sql .= " AND id != :exclude_id";
        }

        $sql .= " LIMIT 1";

        $this->db->query($sql);
        $this->db->bind(':sku', $sku);

        if ($excludeId > 0) {
            $this->db->bind(':exclude_id', $excludeId, \PDO::PARAM_INT);
        }

        return $this->db->fetchOne() !== false;
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function update(array $data, array $criteriaVals): bool
    {
        return parent::update($data, $criteriaVals);
    }

   public function getBySlug(string $slug, ?int $ownerId = null, ?string $siteKey = null): ?object
{
    $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
    $siteSql = $this->siteScopeSql($siteKey);
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE slug = CONVERT(:slug USING latin1)
        {$ownerSql}
        {$siteSql}
        LIMIT 1
    ");
    $this->db->bind(':slug', $slug);
    if ($ownerSql !== '') {
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
    }
    $this->bindSiteScope($siteKey);
    $result = $this->db->fetchOne();
    return $result ?: null;
}

    public function getAll(array $columns = [], int $limit = 0): array
{
    $selectedColumns = '*';

    if (!empty($columns)) {
        $safeColumns = array_map(function ($col) {
            return trim((string)$col);
        }, $columns);

        $safeColumns = array_filter($safeColumns, function ($col) {
            return $col !== '';
        });

        if (!empty($safeColumns)) {
            $selectedColumns = implode(', ', $safeColumns);
        }
    }

    $sql = "
        SELECT {$selectedColumns}
        FROM {$this->table}
        ORDER BY id DESC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT :limit";
    }

    $this->db->query($sql);

    if ($limit > 0) {
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
    }

    $rows = $this->db->fetchAll();

    return $this->appendComputedPricingToProducts($rows);
}

    public function getAllByOwner(int $ownerId, array $columns = [], int $limit = 0, ?string $siteKey = null): array
    {
        $selectedColumns = '*';

        if (!empty($columns)) {
            $safeColumns = array_filter(array_map('trim', array_map('strval', $columns)));
            if (!empty($safeColumns)) {
                $selectedColumns = implode(', ', $safeColumns);
            }
        }

        $sql = "
            SELECT {$selectedColumns}
            FROM {$this->table}
            WHERE id_owner = :id_owner
            {$this->siteScopeSql($siteKey)}
            ORDER BY id DESC
        ";

        if ($limit > 0) {
            $sql .= " LIMIT :limit";
        }

        $this->db->query($sql);
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);

        if ($limit > 0) {
            $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        }

        $rows = $this->db->fetchAll();

        return $this->appendComputedPricingToProducts($rows);
    }

    public function getActivePublic(int $limit = 500, ?int $ownerId = null, ?string $siteKey = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->publicVisibilitySql('store_product', $siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE status = :status
              AND is_public = 1
              {$ownerSql}
              {$siteSql}
            ORDER BY is_featured DESC, id DESC
            LIMIT :limit
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        $rows = $this->db->fetchAll();

        return $this->appendComputedPricingToProducts($rows);
    }

    public function getPublicActiveProducts(int $limit = 500, ?int $ownerId = null, ?string $siteKey = null): array
    {
        return $this->getActivePublic($limit, $ownerId, $siteKey);
    }

    public function getPublicSitemapEntries(int $limit = 1000, ?int $ownerId = null, ?string $siteKey = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->publicVisibilitySql('store_product', $siteKey);
        $this->db->query("
            SELECT id, name, slug, short_description, updated_at, created_at
            FROM {$this->table}
            WHERE status = :status
              AND is_public = 1
              AND slug IS NOT NULL
              AND slug != ''
              {$ownerSql}
              {$siteSql}
            ORDER BY updated_at DESC, created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll() ?: [];
    }

    public function getPublicByCategory(int $categoryId, int $limit = 120, ?int $ownerId = null, ?string $siteKey = null): array
{
    $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND sp.id_owner = :id_owner" : "";
    $siteSql = $this->publicVisibilitySql('store_product', $siteKey, 'sp');
    $sql = "
        SELECT sp.*
        FROM {$this->table} sp
        INNER JOIN store_products_categories spc ON spc.id_product = sp.id
        WHERE spc.id_category = :id_category
          AND sp.status = :status
          AND sp.is_public = 1
          {$ownerSql}
          {$siteSql}
        ORDER BY sp.is_featured DESC, sp.id DESC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT :limit";
    }

    $this->db->query($sql);
    $this->db->bind(':id_category', $categoryId, \PDO::PARAM_INT);
    $this->db->bind(':status', self::STATUS_ACTIVE);
    if ($ownerSql !== '') {
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
    }
    $this->bindSiteScope($siteKey);

    if ($limit > 0) {
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
    }

    $rows = $this->db->fetchAll();

    return $this->appendComputedPricingToProducts($rows);
}

    public function getPublicById(int $id, ?int $ownerId = null, ?string $siteKey = null): ?object
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->publicVisibilitySql('store_product', $siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id = :id
              AND status = :status
              AND is_public = 1
              {$ownerSql}
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':id', $id, \PDO::PARAM_INT);
        $this->db->bind(':status', self::STATUS_ACTIVE);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);

        $product = $this->db->fetchOne();
        if (!$product) {
            return null;
        }

        return $this->appendComputedPricingToProduct($product);
    }

    public function getPublicBySlug(string $slug, ?int $ownerId = null, ?string $siteKey = null): ?object
{
    $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
    $siteSql = $this->publicVisibilitySql('store_product', $siteKey);
    $this->db->query("
        SELECT *
        FROM {$this->table}
        WHERE slug = CONVERT(:slug USING latin1)
          AND status = :status
          AND is_public = 1
          {$ownerSql}
          {$siteSql}
        LIMIT 1
    ");
    $this->db->bind(':slug', $slug);
    $this->db->bind(':status', self::STATUS_ACTIVE);
    if ($ownerSql !== '') {
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
    }
    $this->bindSiteScope($siteKey);

    $product = $this->db->fetchOne();
    if (!$product) {
        return null;
    }

    return $this->appendComputedPricingToProduct($product);
}

    public function getEffectivePrice(object|array $product): float
    {
        $productType = (string)(is_object($product)
            ? ($product->product_type ?? self::PRODUCT_TYPE_FIXED)
            : ($product['product_type'] ?? self::PRODUCT_TYPE_FIXED));

        if ($productType === self::PRODUCT_TYPE_VARIABLE) {
            $productId = (int)(is_object($product) ? ($product->id ?? 0) : ($product['id'] ?? 0));
            if ($productId > 0) {
                $variationsRepo = new StoreProductVariationsRepository();
                $range = $variationsRepo->getPriceRangeByProduct($productId);

                if ((float)$range['min_price'] > 0) {
                    return (float)$range['min_price'];
                }
            }
        }

        $promoPrice = (float)(is_object($product)
            ? ($product->promo_price ?? 0)
            : ($product['promo_price'] ?? 0));

        $price = (float)(is_object($product)
            ? ($product->price ?? 0)
            : ($product['price'] ?? 0));

        return $promoPrice > 0 ? $promoPrice : $price;
    }

    public function getPriceRange(int $productId): array
    {
        $product = $this->getOne(['id' => $productId]);
        if (!$product) {
            return [
                'min_price' => 0.0,
                'max_price' => 0.0,
            ];
        }

        $productType = (string)($product->product_type ?? self::PRODUCT_TYPE_FIXED);

        if ($productType === self::PRODUCT_TYPE_VARIABLE) {
            $variationsRepo = new StoreProductVariationsRepository();
            return $variationsRepo->getPriceRangeByProduct($productId);
        }

        $effectivePrice = $this->getEffectivePrice($product);

        return [
            'min_price' => $effectivePrice,
            'max_price' => $effectivePrice,
        ];
    }

    public function getFullProductDetails(int $id): ?object
    {
        $product = $this->getOne(['id' => $id]);
        if (!$product) {
            return null;
        }

        $product = $this->appendComputedPricingToProduct($product);

        $categoriesRepo = new StoreProductsCategoriesRepository();
        $attributesRepo = new StoreProductsAttributesRepository();
        $nutritionRepo = new StoreProductsNutritionRepository();

        $product->categories = $categoriesRepo->getCategoriesByProduct($id);
        $product->attributes = $attributesRepo->getByProduct($id);
        $product->attributes_grouped = $attributesRepo->getGroupedByProduct($id);
        $product->nutrition = $nutritionRepo->getByProduct($id);

        if (($product->product_type ?? self::PRODUCT_TYPE_FIXED) === self::PRODUCT_TYPE_VARIABLE) {
            $variationsRepo = new StoreProductVariationsRepository();
            $product->variations = $variationsRepo->getDetailedByProduct($id);
        } else {
            $product->variations = [];
        }

        return $product;
    }

    public function getFullPublicProductDetails(int $id, ?int $ownerId = null, ?string $siteKey = null): ?object
    {
        $product = $this->getPublicById($id, $ownerId, $siteKey);
        if (!$product) {
            return null;
        }

        $categoriesRepo = new StoreProductsCategoriesRepository();
        $attributesRepo = new StoreProductsAttributesRepository();
        $nutritionRepo = new StoreProductsNutritionRepository();

        $product->categories = $categoriesRepo->getCategoriesByProduct($id);
        $product->attributes = $attributesRepo->getByProduct($id);
        $product->attributes_grouped = $attributesRepo->getGroupedByProduct($id);
        $product->nutrition = $nutritionRepo->getByProduct($id);

        if (($product->product_type ?? self::PRODUCT_TYPE_FIXED) === self::PRODUCT_TYPE_VARIABLE) {
            $variationsRepo = new StoreProductVariationsRepository();
            $product->variations = $variationsRepo->getDetailedByProduct($id);
        } else {
            $product->variations = [];
        }

        return $product;
    }

    public function appendComputedPricingToProducts(array $products): array
    {
        foreach ($products as $product) {
            $this->appendComputedPricingToProduct($product);
        }

        return $products;
    }

    public function appendComputedPricingToProduct(object|array $product): object|array
    {
        $productType = (string)(is_object($product)
            ? ($product->product_type ?? self::PRODUCT_TYPE_FIXED)
            : ($product['product_type'] ?? self::PRODUCT_TYPE_FIXED));

        $effectivePrice = $this->getEffectivePrice($product);

        if (is_object($product)) {
            $product->product_type = $productType;
            $product->effective_price = $effectivePrice;
            $product->display_price = $effectivePrice;
            $product->is_variable = $productType === self::PRODUCT_TYPE_VARIABLE ? 1 : 0;

            if ($productType === self::PRODUCT_TYPE_VARIABLE) {
                $range = $this->getPriceRange((int)$product->id);
                $product->min_price = (float)$range['min_price'];
                $product->max_price = (float)$range['max_price'];
            } else {
                $product->min_price = $effectivePrice;
                $product->max_price = $effectivePrice;
            }

            return $product;
        }

        $product['product_type'] = $productType;
        $product['effective_price'] = $effectivePrice;
        $product['display_price'] = $effectivePrice;
        $product['is_variable'] = $productType === self::PRODUCT_TYPE_VARIABLE ? 1 : 0;

        if ($productType === self::PRODUCT_TYPE_VARIABLE) {
            $range = $this->getPriceRange((int)$product['id']);
            $product['min_price'] = (float)$range['min_price'];
            $product['max_price'] = (float)$range['max_price'];
        } else {
            $product['min_price'] = $effectivePrice;
            $product['max_price'] = $effectivePrice;
        }

        return $product;
    }

    public function saveProductWithRelations(array $productData, array $categoryIds = [], array $attributeValues = [], array $variations = []): int|false
    {
        $ok = $this->add($productData);
        if (!$ok) {
            return false;
        }

        $productId = (int)$this->getLastId();
        if ($productId <= 0) {
            return false;
        }

        if (!$this->syncRelations($productId, $categoryIds, $attributeValues, $variations)) {
            return false;
        }

        return $productId;
    }

    public function updateProductWithRelations(
        int $productId,
        array $productData,
        array $categoryIds = [],
        array $attributeValues = [],
        array $variations = []
    ): bool {
        $ok = $this->update($productData, ['id' => $productId]);
        if (!$ok) {
            return false;
        }

        return $this->syncRelations($productId, $categoryIds, $attributeValues, $variations);
    }

    public function syncRelations(int $productId, array $categoryIds = [], array $attributeValues = [], array $variations = []): bool
    {
        $categoriesRepo = new StoreCategoriesRepository();
        $attributesRepo = new StoreAttributesRepository();
        $productsCategoriesRepo = new StoreProductsCategoriesRepository();
        $productsAttributesRepo = new StoreProductsAttributesRepository();
        $variationsRepo = new StoreProductVariationsRepository();
        $variationValuesRepo = new StoreProductVariationValuesRepository();
        $product = $this->getOne(['id' => $productId]);
        $productOwnerId = (int)($product->id_owner ?? 0);

        $productsCategoriesRepo->deleteByProduct($productId);
        $productsAttributesRepo->deleteByProduct($productId);
        $variationValuesRepo->deleteByProduct($productId);
        $variationsRepo->deleteByProduct($productId);

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        foreach ($categoryIds as $categoryId) {
            $categoryCriteria = ['id' => $categoryId];
            if ($productOwnerId > 0) {
                $categoryCriteria['id_owner'] = $productOwnerId;
            }

            $category = $categoriesRepo->getOne($categoryCriteria);
            if (!$category) {
                continue;
            }

            $ok = $productsCategoriesRepo->add([
                'id_product' => $productId,
                'id_category' => $categoryId
            ]);

            if (!$ok) {
                return false;
            }
        }

        foreach ($attributeValues as $attributeId => $valueIds) {
            $attributeId = (int)$attributeId;
            if ($attributeId <= 0 || !is_array($valueIds)) {
                continue;
            }

            $attributeCriteria = ['id' => $attributeId];
            if ($productOwnerId > 0) {
                $attributeCriteria['id_owner'] = $productOwnerId;
            }

            $attribute = $attributesRepo->getOne($attributeCriteria);
            if (!$attribute) {
                continue;
            }

            $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds))));
            foreach ($valueIds as $valueId) {
                $ok = $productsAttributesRepo->add([
                    'id_product' => $productId,
                    'id_attribute' => $attributeId,
                    'id_attribute_value' => $valueId
                ]);

                if (!$ok) {
                    return false;
                }
            }
        }

        if (!empty($variations)) {
            if (!$variationsRepo->replaceByProduct($productId, $variations)) {
                return false;
            }
        }

        return true;
    }

    public function getPlainPriceForStorage(array $data): float
    {
        $productType = (string)($data['product_type'] ?? self::PRODUCT_TYPE_FIXED);

        if ($productType === self::PRODUCT_TYPE_VARIABLE) {
            return 0.00;
        }

        return (float)($data['price'] ?? 0);
    }

    public function normalizeProductType(?string $productType): string
    {
        $productType = strtoupper(trim((string)$productType));

        return in_array($productType, [
            self::PRODUCT_TYPE_FIXED,
            self::PRODUCT_TYPE_VARIABLE
        ], true)
            ? $productType
            : self::PRODUCT_TYPE_FIXED;
    }
}
