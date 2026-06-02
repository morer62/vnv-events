<?php

use App\Repositories\StoreCartsRepository;
use App\Repositories\StoreCartItemsRepository;
use App\Repositories\StoreProductsRepository;
use App\Repositories\StoreProductVariationsRepository;
use App\Services\StoreCouponService;
use App\Services\LoginService;
use App\Utils\AvomealContext;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", []);
});

$router->post(function () {
    header('Content-Type: application/json');

    $productsRepo = new StoreProductsRepository();
    $variationsRepo = new StoreProductVariationsRepository();
    $cartsRepo = new StoreCartsRepository();
    $cartItemsRepo = new StoreCartItemsRepository();

    $payload = json_decode(file_get_contents("php://input"), true);
    $ownerId = AvomealContext::ownerId();

    if (!$payload || !is_array($payload)) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid payload"
        ]);
        return '';
    }

    $guestName = trim($payload['guest_name'] ?? '');
    $guestEmail = trim($payload['guest_email'] ?? '');
    $guestPhone = trim($payload['guest_phone'] ?? '');
    $city = trim($payload['city'] ?? '');
    $couponCode = trim((string)($payload['coupon_code'] ?? ''));
    $pricingMode = StoreCartsRepository::PRICING_PAYG;
    $sessionToken = trim($payload['session_token'] ?? '');
    $items = $payload['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Cart is empty"
        ]);
        return '';
    }

    $cleanItems = [];
    $quantityTotal = 0;
    $subtotal = 0.00;

    foreach ($items as $item) {
        $productId = intval($item['id_product'] ?? 0);
        $variationId = intval($item['id_product_variation'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }

        $product = $productsRepo->getPublicById($productId, $ownerId);

        if (!$product) {
            continue;
        }

        $productType = $product->product_type ?? StoreProductsRepository::PRODUCT_TYPE_FIXED;

        $unitPrice = 0.00;
        $stockQuantity = 0;
        $minPurchaseQty = 1;
        $maxPurchaseQty = null;
        $variationName = null;
        $variationOptions = null;
        $resolvedVariationId = null;

        if ($productType === StoreProductsRepository::PRODUCT_TYPE_VARIABLE) {
            if ($variationId <= 0) {
                continue;
            }

            $variation = $variationsRepo->getByProductAndId($productId, $variationId);
            if (!$variation || ($variation->status ?? 'INACTIVE') !== 'ACTIVE') {
                continue;
            }

            $unitPrice = $variationsRepo->getEffectivePrice($variation);
            $stockQuantity = (int)($variation->stock_quantity ?? 0);
            $minPurchaseQty = max(1, (int)($variation->min_purchase_qty ?? 1));
            $maxPurchaseQty = isset($variation->max_purchase_qty) && $variation->max_purchase_qty !== null
                ? (int)$variation->max_purchase_qty
                : null;
            $variationName = (string)($variation->name ?? '');
            $variationOptions = $variation->attribute_values ?? [];
            $resolvedVariationId = (int)$variation->id;
        } else {
            $unitPrice = (float)$productsRepo->getEffectivePrice($product);
            $stockQuantity = (int)($product->stock_quantity ?? 0);
            $minPurchaseQty = max(1, (int)($product->min_purchase_qty ?? 1));
            $maxPurchaseQty = isset($product->max_purchase_qty) && $product->max_purchase_qty !== null
                ? (int)$product->max_purchase_qty
                : null;
        }

        if ($quantity < $minPurchaseQty) {
            $quantity = $minPurchaseQty;
        }

        if ($stockQuantity > 0 && $quantity > $stockQuantity) {
            $quantity = $stockQuantity;
        }

        if ($maxPurchaseQty !== null && $maxPurchaseQty > 0 && $quantity > $maxPurchaseQty) {
            $quantity = $maxPurchaseQty;
        }

        if ($quantity <= 0 || $unitPrice <= 0) {
            continue;
        }

        $lineTotal = round($unitPrice * $quantity, 2);

        $cleanItems[] = [
            'id_product' => (int)$product->id,
            'id_product_variation' => $resolvedVariationId,
            'product_name_snapshot' => $product->name,
            'variation_name_snapshot' => $variationName,
            'variation_options_snapshot' => $variationOptions ? json_encode($variationOptions, JSON_UNESCAPED_UNICODE) : null,
            'unit_price' => $unitPrice,
            'pricing_mode' => StoreCartItemsRepository::PRICING_PAYG,
            'quantity' => $quantity,
            'line_total' => $lineTotal
        ];

        $quantityTotal += $quantity;
        $subtotal += $lineTotal;
    }

    if (count($cleanItems) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "No valid products found"
        ]);
        return '';
    }

    $userId = null;
    try {
        $session = LoginService::getSession();
        if ($session) {
            $userId = $session->getId();
        }
    } catch (Throwable $e) {
        $userId = null;
    }

    $subtotal = round($subtotal, 2);
    $discount = 0.00;
    $couponId = null;
    $total = $subtotal;

    $couponService = new StoreCouponService();

    if ($couponCode !== '') {
        try {
            $couponResult = $couponService->validateAndCalculate(
                $ownerId,
                $couponCode,
                $subtotal,
                $pricingMode,
                $userId ? (int)$userId : null,
                $guestEmail !== '' ? $guestEmail : null
            );

            if (empty($couponResult['ok'])) {
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid coupon"
                ]);
                return '';
            }

            $discount = round((float)($couponResult['discount'] ?? 0), 2);
            $total = round((float)($couponResult['total'] ?? $subtotal), 2);
            $couponCode = (string)($couponResult['code'] ?? $couponCode);
            $couponId = (int)(($couponResult['coupon']->id ?? 0));
        } catch (Throwable $e) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid coupon"
            ]);
            return '';
        }
    } else {
        $couponCode = '';
    }

    if ($sessionToken === '') {
        $sessionToken = $cartsRepo->generateToken();
    }

    $cart = $cartsRepo->getBySessionToken($sessionToken, $ownerId);

    if (!$cart) {
        $recoveryToken = $cartsRepo->generateToken();

        $ok = $cartsRepo->add([
            'id_owner' => $ownerId,
            'id_user' => $userId,
            'session_token' => $sessionToken,
            'recovery_token' => $recoveryToken,
            'guest_name' => $guestName ?: null,
            'guest_email' => $guestEmail ?: null,
            'guest_phone' => $guestPhone ?: null,
            'city' => $city ?: null,
            'audience_type' => null,
            'meal_style' => null,
            'pricing_mode' => $pricingMode,
            'items_count' => count($cleanItems),
            'meals_count' => $quantityTotal,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_code' => $couponCode !== '' ? $couponCode : null,
            'id_coupon' => $couponId ?: null,
            'coupon_discount' => $discount,
            'total' => $total,
            'status' => StoreCartsRepository::STATUS_ACTIVE,
            'last_step' => 'cart',
            'abandoned_email_sent' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s')
        ]);

        if (!$ok) {
            echo json_encode([
                "success" => false,
                "message" => "Could not create cart"
            ]);
            return '';
        }

        $cartId = $cartsRepo->getLastId();
    } else {
        $cartId = (int)$cart->id;

        $ok = $cartsRepo->update([
            'id_user' => $userId,
            'guest_name' => $guestName ?: null,
            'guest_email' => $guestEmail ?: null,
            'guest_phone' => $guestPhone ?: null,
            'city' => $city ?: null,
            'audience_type' => null,
            'meal_style' => null,
            'pricing_mode' => $pricingMode,
            'items_count' => count($cleanItems),
            'meals_count' => $quantityTotal,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_code' => $couponCode !== '' ? $couponCode : null,
            'id_coupon' => $couponId ?: null,
            'coupon_discount' => $discount,
            'total' => $total,
            'status' => StoreCartsRepository::STATUS_ACTIVE,
            'last_step' => 'cart',
            'updated_at' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $cartId
        ]);

        if (!$ok) {
            echo json_encode([
                "success" => false,
                "message" => "Could not update cart"
            ]);
            return '';
        }

        $cartItemsRepo->deleteByCart($cartId);
    }

    foreach ($cleanItems as $item) {
        $ok = $cartItemsRepo->add([
            'id_owner' => $ownerId,
            'id_cart' => $cartId,
            'id_product' => $item['id_product'],
            'id_product_variation' => $item['id_product_variation'],
            'product_name_snapshot' => $item['product_name_snapshot'],
            'variation_name_snapshot' => $item['variation_name_snapshot'],
            'variation_options_snapshot' => $item['variation_options_snapshot'],
            'unit_price' => $item['unit_price'],
            'pricing_mode' => $item['pricing_mode'],
            'quantity' => $item['quantity'],
            'line_total' => $item['line_total']
        ]);

        if (!$ok) {
            echo json_encode([
                "success" => false,
                "message" => "Could not save cart items"
            ]);
            return '';
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Cart saved successfully",
        "cart_id" => $cartId,
        "session_token" => $sessionToken,
        "pricing_mode" => $pricingMode,
        "quantity_total" => $quantityTotal,
        "subtotal" => $subtotal,
        "discount" => $discount,
        "coupon_code" => $couponCode !== '' ? $couponCode : null,
        "total" => $total,
        "redirect" => \App\Utils\LocationUtils::pathFor("store/checkout")
    ]);

    return '';
});

$router->run();
