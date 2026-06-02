<?php

use App\Repositories\StoreAttributesRepository;
use App\Repositories\StoreAttributeValuesRepository;
use App\Repositories\StoreCategoriesRepository;
use App\Repositories\StoreProductsCategoriesRepository;
use App\Repositories\StoreProductsRepository;
use App\Repositories\StoreProductVariationsRepository;
use App\Utils\FileUtils;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $productsRepo = new StoreProductsRepository();
    $categoriesRepo = new StoreCategoriesRepository();
    $attributesRepo = new StoreAttributesRepository();
    $attributeValuesRepo = new StoreAttributeValuesRepository();
    $productsCategoriesRepo = new StoreProductsCategoriesRepository();
    $variationsRepo = new StoreProductVariationsRepository();
    $ownerId = AvomealContext::ownerId();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid product.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $productBase = $productsRepo->getOne(['id' => $id, 'id_owner' => $ownerId]);
    $product = $productBase ? $productsRepo->getFullProductDetails($id) : null;

    if (!$product) {
        MessageUtil::setMessage("Product not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $attributes = $attributesRepo->getActive($ownerId);
    foreach ($attributes as $attribute) {
        $attribute->values = $attributeValuesRepo->getActiveByAttribute((int)$attribute->id, $ownerId);
    }

    $selectedCategoryIds = $productsCategoriesRepo->getCategoryIdsByProduct($id);
    $selectedValueIds = [];

    if (!empty($product->attributes)) {
        foreach ($product->attributes as $row) {
            $selectedValueIds[] = (int)(is_object($row) ? $row->id_attribute_value : $row['id_attribute_value']);
        }
    }

    $variations = $variationsRepo->getDetailedByProduct($id);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "product" => $product,
        "categories" => $categoriesRepo->getActive($ownerId),
        "attributes" => $attributes,
        "selected_category_ids" => $selectedCategoryIds,
        "selected_value_ids" => $selectedValueIds,
        "variations" => $variations,
        "product_types" => [
            StoreProductsRepository::PRODUCT_TYPE_FIXED,
            StoreProductsRepository::PRODUCT_TYPE_VARIABLE,
        ]
    ]);
});

$router->post(function () {
    $productsRepo = new StoreProductsRepository();
    $ownerId = AvomealContext::ownerId();
    $logDir = LocationUtils::getRootLocation() . '/.logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/app_error_' . date('Y-m-d') . '.log';
    if (!file_exists($logFile)) {
        @touch($logFile);
    }

    $logContext = '[STORE_PRODUCTS_EDIT]';
    $logDebug = static function (string $message, array $extra = []) use ($logDir, $logFile, $logContext): void {
        $payload = [
            'ts' => date('Y-m-d H:i:s'),
            'message' => $message,
        ];

        if (!empty($extra)) {
            $payload['extra'] = $extra;
        }

        $line = $logContext . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $written = @file_put_contents($logFile, $line, FILE_APPEND);

        if ($written === false) {
            error_log($line);
            error_log($logContext . ' Failed writing to log file: ' . $logFile . ' (dir: ' . $logDir . ')');
        }
    };

    $id = intval($_POST['id'] ?? 0);
    $logDebug('POST received', [
        'id' => $id,
        'post_keys' => array_keys($_POST ?? []),
        'has_main_image' => FileUtils::hasFile($_FILES, 'main_image'),
    ]);

    if ($id <= 0) {
        $logDebug('Validation failed: invalid product id', ['id' => $id]);
        MessageUtil::setMessage("Invalid product.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $existingProduct = $productsRepo->getOne(['id' => $id, 'id_owner' => $ownerId]);

    if (!$existingProduct) {
        $logDebug('Validation failed: product not found', ['id' => $id]);
        MessageUtil::setMessage("Product not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $name = trim($_POST['name'] ?? '');
    $slugInput = trim($_POST['slug'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $shortDescription = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $productType = $productsRepo->normalizeProductType($_POST['product_type'] ?? StoreProductsRepository::PRODUCT_TYPE_FIXED);

    $priceRaw = trim($_POST['price'] ?? '');
    $promoPriceRaw = trim($_POST['promo_price'] ?? '');

    $price = $priceRaw !== '' ? (float)$priceRaw : 0;
    $promoPrice = $promoPriceRaw !== '' ? (float)$promoPriceRaw : null;

    $stockQuantity = (int)($_POST['stock_quantity'] ?? 0);
    $minPurchaseQty = (int)($_POST['min_purchase_qty'] ?? 1);
    $maxPurchaseQtyRaw = trim($_POST['max_purchase_qty'] ?? '');
    $maxPurchaseQty = $maxPurchaseQtyRaw !== '' ? (int)$maxPurchaseQtyRaw : null;

    $status = trim($_POST['status'] ?? StoreProductsRepository::STATUS_ACTIVE);
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $isPublic = isset($_POST['is_public']) ? 1 : 0;

    $categoryIds = $_POST['category_ids'] ?? [];
    $attributeValues = $_POST['attribute_values'] ?? [];

    if ($name === '') {
        $logDebug('Validation failed: empty product name', ['id' => $id]);
        MessageUtil::setMessage("Product name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($productType === StoreProductsRepository::PRODUCT_TYPE_FIXED && $price <= 0) {
        $logDebug('Validation failed: fixed price <= 0', [
            'id' => $id,
            'price_raw' => $priceRaw,
            'price' => $price,
        ]);
        MessageUtil::setMessage("Fixed products must have a price greater than zero. Please enter a valid price to update this product.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($stockQuantity < 0) {
        $logDebug('Validation failed: stock < 0', ['id' => $id, 'stock_quantity' => $stockQuantity]);
        MessageUtil::setMessage("Stock cannot be negative.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($minPurchaseQty <= 0) {
        $logDebug('Validation failed: min_purchase_qty <= 0', ['id' => $id, 'min_purchase_qty' => $minPurchaseQty]);
        MessageUtil::setMessage("Minimum purchase quantity must be at least 1.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($maxPurchaseQty !== null && $maxPurchaseQty > 0 && $maxPurchaseQty < $minPurchaseQty) {
        $logDebug('Validation failed: max_purchase_qty < min_purchase_qty', [
            'id' => $id,
            'min_purchase_qty' => $minPurchaseQty,
            'max_purchase_qty' => $maxPurchaseQty,
        ]);
        MessageUtil::setMessage("Maximum purchase quantity cannot be lower than minimum purchase quantity.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($sku !== '' && $productsRepo->skuExists($sku, $id)) {
        $logDebug('Validation failed: duplicated SKU', ['id' => $id, 'sku' => $sku]);
        MessageUtil::setMessage("SKU already exists.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    $variations = [];
    if ($productType === StoreProductsRepository::PRODUCT_TYPE_VARIABLE) {
        $variationNames = $_POST['variation_name'] ?? [];
        $variationSlugs = $_POST['variation_slug'] ?? [];
        $variationSkus = $_POST['variation_sku'] ?? [];
        $variationPrices = $_POST['variation_price'] ?? [];
        $variationPromoPrices = $_POST['variation_promo_price'] ?? [];
        $variationStocks = $_POST['variation_stock_quantity'] ?? [];
        $variationMinQtys = $_POST['variation_min_purchase_qty'] ?? [];
        $variationMaxQtys = $_POST['variation_max_purchase_qty'] ?? [];
        $variationSortOrders = $_POST['variation_sort_order'] ?? [];
        $variationStatuses = $_POST['variation_status'] ?? [];
        $variationAttributePairs = $_POST['variation_attribute_pairs'] ?? [];

        $rowCount = max(
            is_array($variationNames) ? count($variationNames) : 0,
            is_array($variationPrices) ? count($variationPrices) : 0
        );

        $seenVariationSkus = [];

        for ($i = 0; $i < $rowCount; $i++) {
            $variationName = trim((string)($variationNames[$i] ?? ''));
            $variationSku = trim((string)($variationSkus[$i] ?? ''));
            $variationPriceRaw = trim((string)($variationPrices[$i] ?? ''));
            $variationPromoPriceRaw = trim((string)($variationPromoPrices[$i] ?? ''));
            $variationStockRaw = trim((string)($variationStocks[$i] ?? '0'));
            $variationMinQtyRaw = trim((string)($variationMinQtys[$i] ?? '1'));
            $variationMaxQtyRaw = trim((string)($variationMaxQtys[$i] ?? ''));
            $variationSortOrderRaw = trim((string)($variationSortOrders[$i] ?? (string)$i));
            $variationStatus = trim((string)($variationStatuses[$i] ?? 'ACTIVE'));

            if ($variationName === '' && $variationPriceRaw === '' && $variationSku === '') {
                continue;
            }

            if ($variationName === '') {
                MessageUtil::setMessage("Each variation must have a name.");
                LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
            }

            $variationPrice = $variationPriceRaw !== '' ? (float)$variationPriceRaw : 0;
            if ($variationPrice <= 0) {
                MessageUtil::setMessage("Each variation must have a price greater than zero.");
                LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
            }

            $variationStock = (int)$variationStockRaw;
            if ($variationStock < 0) {
                MessageUtil::setMessage("Variation stock cannot be negative.");
                LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
            }

            $variationMinQty = max(1, (int)$variationMinQtyRaw);
            $variationMaxQty = $variationMaxQtyRaw !== '' ? (int)$variationMaxQtyRaw : null;

            if ($variationMaxQty !== null && $variationMaxQty > 0 && $variationMaxQty < $variationMinQty) {
                MessageUtil::setMessage("Variation max quantity cannot be lower than min quantity.");
                LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
            }

            if ($variationSku !== '') {
                $variationSkuKey = strtolower($variationSku);

                if (isset($seenVariationSkus[$variationSkuKey])) {
                    MessageUtil::setMessage("Variation SKUs cannot be repeated inside the same product.");
                    LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
                }

                $seenVariationSkus[$variationSkuKey] = true;

                if ($sku !== '' && strtolower($sku) === $variationSkuKey) {
                    MessageUtil::setMessage("A variation SKU cannot be the same as the main product SKU.");
                    LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
                }
            }

            $pairs = [];
            if (isset($variationAttributePairs[$i]) && is_array($variationAttributePairs[$i])) {
                foreach ($variationAttributePairs[$i] as $attributeId => $attributeValueId) {
                    $attributeId = (int)$attributeId;
                    $attributeValueId = (int)$attributeValueId;

                    if ($attributeId <= 0 || $attributeValueId <= 0) {
                        continue;
                    }

                    $pairs[] = [
                        'id_attribute' => $attributeId,
                        'id_attribute_value' => $attributeValueId
                    ];
                }
            }

            $variations[] = [
                'name' => $variationName,
                'slug' => trim((string)($variationSlugs[$i] ?? '')),
                'sku' => $variationSku,
                'price' => $variationPrice,
                'promo_price' => $variationPromoPriceRaw !== '' ? (float)$variationPromoPriceRaw : null,
                'stock_quantity' => $variationStock,
                'min_purchase_qty' => $variationMinQty,
                'max_purchase_qty' => $variationMaxQty,
                'sort_order' => $variationSortOrderRaw !== '' ? (int)$variationSortOrderRaw : $i,
                'status' => in_array($variationStatus, ['ACTIVE', 'INACTIVE'], true) ? $variationStatus : 'ACTIVE',
                'attribute_pairs' => $pairs
            ];
        }

        if (count($variations) === 0) {
            MessageUtil::setMessage("Variable products must include at least one variation.");
            LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
        }
    }

    $mainImage = $existingProduct->main_image ?? null;

    if (FileUtils::hasFile($_FILES, 'main_image')) {
        try {
            $mainImage = FileUtils::saveFile($_FILES['main_image'], "store-products");
        } catch (Exception $e) {
            MessageUtil::setMessage("Error uploading main image.");
            LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
        }
    }

    $slugBase = $slugInput !== '' ? $slugInput : $name;
    $slug = $productsRepo->generateUniqueSlug($slugBase, $id);

    $productData = [
        'id_owner' => $ownerId,
        'name' => $name,
        'slug' => $slug,
        'sku' => $sku !== '' ? $sku : null,
        'short_description' => $shortDescription !== '' ? $shortDescription : null,
        'description' => $description !== '' ? $description : null,
        'product_type' => $productType,
        'price' => $productsRepo->getPlainPriceForStorage([
            'product_type' => $productType,
            'price' => $price
        ]),
        'promo_price' => $productType === StoreProductsRepository::PRODUCT_TYPE_FIXED ? $promoPrice : null,
        'main_image' => $mainImage,
        'stock_quantity' => $productType === StoreProductsRepository::PRODUCT_TYPE_FIXED ? $stockQuantity : 0,
        'min_purchase_qty' => $productType === StoreProductsRepository::PRODUCT_TYPE_FIXED ? $minPurchaseQty : 1,
        'max_purchase_qty' => $productType === StoreProductsRepository::PRODUCT_TYPE_FIXED ? $maxPurchaseQty : null,
        'is_featured' => $isFeatured,
        'is_public' => $isPublic,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $logDebug('Calling updateProductWithRelations', [
        'id' => $id,
        'product_type' => $productType,
        'price' => $productData['price'],
        'promo_price' => $productData['promo_price'],
        'stock_quantity' => $productData['stock_quantity'],
        'categories_count' => is_array($categoryIds) ? count($categoryIds) : 0,
        'attribute_groups_count' => is_array($attributeValues) ? count($attributeValues) : 0,
        'variations_count' => count($variations),
    ]);

    try {
        $ok = $productsRepo->updateProductWithRelations(
            $id,
            $productData,
            is_array($categoryIds) ? $categoryIds : [],
            is_array($attributeValues) ? $attributeValues : [],
            $variations
        );
    } catch (\Throwable $e) {
        $logDebug('Exception during updateProductWithRelations', [
            'id' => $id,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        MessageUtil::setMessage("Product could not be updated. Check logs for details.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    $logDebug('updateProductWithRelations result', [
        'id' => $id,
        'ok' => (bool)$ok,
    ]);

    if (!$ok) {
        $logDebug('Update failed: repository returned false', ['id' => $id]);
        MessageUtil::setMessage("Product could not be updated.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    $logDebug('Update success', ['id' => $id]);
    MessageUtil::setMessage("Product updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
});

$router->run();
