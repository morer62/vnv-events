<?php

use App\Repositories\StoreAttributesRepository;
use App\Repositories\StoreAttributeValuesRepository;
use App\Repositories\StoreCategoriesRepository;
use App\Repositories\StoreProductsAttributesRepository;
use App\Repositories\StoreProductsCategoriesRepository;
use App\Repositories\StoreProductsNutritionRepository;
use App\Repositories\StoreProductsRepository;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\StoreProductsAudiencesRepository;
use App\Repositories\StoreProductsMealStylesRepository;

$router = new Router();

$router->get(function () {
    $productsRepo = new StoreProductsRepository();
    $categoriesRepo = new StoreCategoriesRepository();
    $attributesRepo = new StoreAttributesRepository();
    $attributeValuesRepo = new StoreAttributeValuesRepository();
    $productsCategoriesRepo = new StoreProductsCategoriesRepository();
    $productsAttributesRepo = new StoreProductsAttributesRepository();
    $nutritionRepo = new StoreProductsNutritionRepository();
    $productsAudiencesRepo = new StoreProductsAudiencesRepository();
    $productsMealStylesRepo = new StoreProductsMealStylesRepository();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid product.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $product = $productsRepo->getOne(['id' => $id]);

    if (!$product) {
        MessageUtil::setMessage("Product not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $attributes = $attributesRepo->getActive();
    foreach ($attributes as $attribute) {
        $attribute->values = $attributeValuesRepo->getActiveByAttribute((int)$attribute->id);
    }

    $selectedCategoryIds = $productsCategoriesRepo->getCategoryIdsByProduct($id);
    $selectedAttributes = $productsAttributesRepo->getByProduct($id);
    $nutrition = $nutritionRepo->getByProduct($id);

    $selectedValueIds = [];
    foreach ($selectedAttributes as $row) {
        $selectedValueIds[] = (int)(is_object($row) ? $row->id_attribute_value : $row['id_attribute_value']);
    }

    $selectedAudiences = $productsAudiencesRepo->getAudienceTypesByProduct($id);
$selectedMealStyles = $productsMealStylesRepo->getMealStylesByProduct($id);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "product" => $product,
        "categories" => $categoriesRepo->getActive(),
        "attributes" => $attributes,
        "selected_category_ids" => $selectedCategoryIds,
        "selected_value_ids" => $selectedValueIds,
        "nutrition" => $nutrition,
        "selected_audiences" => $selectedAudiences,
        "selected_meal_styles" => $selectedMealStyles,
    ]);
});

$router->post(function () {
    $productsRepo = new StoreProductsRepository();
    $categoriesRepo = new StoreCategoriesRepository();
    $attributesRepo = new StoreAttributesRepository();
    $productsCategoriesRepo = new StoreProductsCategoriesRepository();
    $productsAttributesRepo = new StoreProductsAttributesRepository();
    $nutritionRepo = new StoreProductsNutritionRepository();
    $productsAudiencesRepo = new StoreProductsAudiencesRepository();
    $productsMealStylesRepo = new StoreProductsMealStylesRepository();

    $id = intval($_POST['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid product.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $product = $productsRepo->getOne(['id' => $id]);

    if (!$product) {
        MessageUtil::setMessage("Product not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $shortDescription = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $promoPriceRaw = trim($_POST['promo_price'] ?? '');
    $promoPrice = $promoPriceRaw !== '' ? floatval($promoPriceRaw) : null;
    $stockQuantity = intval($_POST['stock_quantity'] ?? 0);
    $minPurchaseQty = intval($_POST['min_purchase_qty'] ?? 1);
    $maxPurchaseQtyRaw = trim($_POST['max_purchase_qty'] ?? '');
    $maxPurchaseQty = $maxPurchaseQtyRaw !== '' ? intval($maxPurchaseQtyRaw) : null;
    $status = trim($_POST['status'] ?? StoreProductsRepository::STATUS_ACTIVE);
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $isPublic = isset($_POST['is_public']) ? 1 : 0;

    $categoryIds = $_POST['category_ids'] ?? [];
    $attributeValues = $_POST['attribute_values'] ?? [];
    $audiences = $_POST['audiences'] ?? [];
    $mealStyles = $_POST['meal_styles'] ?? [];

    if ($name === '') {
        MessageUtil::setMessage("Product name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($price <= 0) {
        MessageUtil::setMessage("Price must be greater than zero.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($stockQuantity < 0) {
        MessageUtil::setMessage("Stock cannot be negative.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($minPurchaseQty <= 0) {
        MessageUtil::setMessage("Minimum purchase quantity must be at least 1.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    if ($sku !== '' && $productsRepo->skuExists($sku, $id)) {
        MessageUtil::setMessage("SKU already exists.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    $mainImage = $product->main_image;

    if (FileUtils::hasFile($_FILES, 'main_image')) {
        try {
            $mainImage = FileUtils::saveFile($_FILES['main_image'], "store-products");
        } catch (Exception $e) {
            MessageUtil::setMessage("Error uploading main image.");
            LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
        }
    }

    $slug = $productsRepo->generateUniqueSlug($name);

    $ok = $productsRepo->update([
        'name' => $name,
        'slug' => $slug,
        'sku' => $sku ?: null,
        'short_description' => $shortDescription ?: null,
        'description' => $description ?: null,
        'price' => $price,
        'promo_price' => $promoPrice,
        'main_image' => $mainImage,
        'stock_quantity' => $stockQuantity,
        'min_purchase_qty' => $minPurchaseQty,
        'max_purchase_qty' => $maxPurchaseQty,
        'is_featured' => $isFeatured,
        'is_public' => $isPublic,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $id
    ]);

    if (is_array($audiences)) {
    $audiences = array_values(array_unique(array_filter(array_map('trim', $audiences))));
    $productsAudiencesRepo->replaceByProduct($id, $audiences);
} else {
    $productsAudiencesRepo->deleteByProduct($id);
}

if (is_array($mealStyles)) {
    $mealStyles = array_values(array_unique(array_filter(array_map('trim', $mealStyles))));
    $productsMealStylesRepo->replaceByProduct($id, $mealStyles);
} else {
    $productsMealStylesRepo->deleteByProduct($id);
}

    if (!$ok) {
        MessageUtil::setMessage("Product could not be updated.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/edit?id=" . $id);
    }

    $productsCategoriesRepo->deleteByProduct($id);
    if (is_array($categoryIds)) {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        foreach ($categoryIds as $categoryId) {
            $category = $categoriesRepo->getOne(['id' => $categoryId]);
            if ($category) {
                $productsCategoriesRepo->add([
                    'id_product' => $id,
                    'id_category' => $categoryId
                ]);
            }
        }
    }

    $productsAttributesRepo->deleteByProduct($id);
    if (is_array($attributeValues)) {
        foreach ($attributeValues as $attributeId => $valueIds) {
            $attributeId = intval($attributeId);
            if (!is_array($valueIds) || $attributeId <= 0) {
                continue;
            }

            $attribute = $attributesRepo->getOne(['id' => $attributeId]);
            if (!$attribute) {
                continue;
            }

            $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds))));

            foreach ($valueIds as $valueId) {
                $productsAttributesRepo->add([
                    'id_product' => $id,
                    'id_attribute' => $attributeId,
                    'id_attribute_value' => $valueId
                ]);
            }
        }
    }

    $hasNutrition =
        trim($_POST['serving_size'] ?? '') !== '' ||
        trim($_POST['ingredients'] ?? '') !== '' ||
        trim($_POST['calories'] ?? '') !== '' ||
        trim($_POST['protein'] ?? '') !== '' ||
        trim($_POST['carbohydrates'] ?? '') !== '' ||
        trim($_POST['fat'] ?? '') !== '' ||
        trim($_POST['fiber'] ?? '') !== '' ||
        trim($_POST['sugar'] ?? '') !== '' ||
        trim($_POST['sodium'] ?? '') !== '';

    if ($hasNutrition) {
        $nutritionRepo->saveForProduct($id, [
            'calories' => trim($_POST['calories'] ?? '') !== '' ? intval($_POST['calories']) : null,
            'protein' => trim($_POST['protein'] ?? '') !== '' ? floatval($_POST['protein']) : null,
            'carbohydrates' => trim($_POST['carbohydrates'] ?? '') !== '' ? floatval($_POST['carbohydrates']) : null,
            'fat' => trim($_POST['fat'] ?? '') !== '' ? floatval($_POST['fat']) : null,
            'fiber' => trim($_POST['fiber'] ?? '') !== '' ? floatval($_POST['fiber']) : null,
            'sugar' => trim($_POST['sugar'] ?? '') !== '' ? floatval($_POST['sugar']) : null,
            'sodium' => trim($_POST['sodium'] ?? '') !== '' ? floatval($_POST['sodium']) : null,
            'serving_size' => trim($_POST['serving_size'] ?? '') ?: null,
            'ingredients' => trim($_POST['ingredients'] ?? '') ?: null
        ]);
    }

    MessageUtil::setMessage("Product updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/products/home");

    
});

$router->run();