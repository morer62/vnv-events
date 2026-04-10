<?php

use App\Repositories\StoreCartsRepository;
use App\Repositories\StoreCartItemsRepository;
use App\Repositories\StoreProductsRepository;
use App\Services\StoreCouponService;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", []);
});

$router->post(function () {
    header('Content-Type: application/json');

    $productsRepo = new StoreProductsRepository();
    $cartsRepo = new StoreCartsRepository();
    $cartItemsRepo = new StoreCartItemsRepository();

    $payload = json_decode(file_get_contents("php://input"), true);

    if (!$payload || !is_array($payload)) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid payload"
        ]);
        return '';
    }

    $audience = trim($payload['audience'] ?? '');
    $mealStyle = trim($payload['meal_style'] ?? '');
    $guestName = trim($payload['guest_name'] ?? '');
    $guestEmail = trim($payload['guest_email'] ?? '');
    $guestPhone = trim($payload['guest_phone'] ?? '');
    $city = trim($payload['city'] ?? '');
    $couponCode = trim((string)($payload['coupon_code'] ?? ''));
    $membershipEnabled = !empty($payload['membership_enabled']);
    $pricingMode = $membershipEnabled
        ? StoreCartsRepository::PRICING_SUBSCRIPTION
        : StoreCartsRepository::PRICING_PAYG;
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
    $mealsCount = 0;
    $subtotal = 0.00;

    foreach ($items as $item) {
        $productId = intval($item['id_product'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }

        $product = $productsRepo->getPublicById($productId);

        if (!$product) {
            continue;
        }

        if ((int)$product->stock_quantity > 0 && $quantity > (int)$product->stock_quantity) {
            $quantity = (int)$product->stock_quantity;
        }

        if ($quantity <= 0) {
            continue;
        }

        $unitPrice = (float)$product->price;
        $lineTotal = round($unitPrice * $quantity, 2);

        $cleanItems[] = [
            'id_product' => (int)$product->id,
            'product_name_snapshot' => $product->name,
            'unit_price' => $unitPrice,
            'pricing_mode' => $pricingMode === StoreCartItemsRepository::PRICING_SUBSCRIPTION
                ? StoreCartItemsRepository::PRICING_SUBSCRIPTION
                : StoreCartItemsRepository::PRICING_PAYG,
            'quantity' => $quantity,
            'line_total' => $lineTotal
        ];

        $mealsCount += $quantity;
        $subtotal += $lineTotal;
    }

    if (count($cleanItems) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "No valid products found"
        ]);
        return '';
    }

    if ($mealsCount < 5) {
        echo json_encode([
            "success" => false,
            "message" => "Minimum 5 meals required"
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

    /**
     * Tries to validate the coupon in a permissive way:
     * - current pricing mode
     * - alternate pricing mode
     * - with current user/email
     * - without user/email
     *
     * Goal: do not block new customers because of membership mode or prior usage checks.
     */
    $resolveCouponPermissive = function (
        StoreCouponService $couponService,
        string $couponCode,
        float $subtotal,
        string $pricingMode,
        ?int $userId,
        ?string $guestEmail
    ) {
        $modesToTry = array_values(array_unique([
            $pricingMode,
            $pricingMode === StoreCartsRepository::PRICING_SUBSCRIPTION
                ? StoreCartsRepository::PRICING_PAYG
                : StoreCartsRepository::PRICING_SUBSCRIPTION
        ]));

        $userCandidates = [];
        if ($userId) {
            $userCandidates[] = (int)$userId;
        }
        $userCandidates[] = null;

        $emailCandidates = [];
        if ($guestEmail !== null && trim($guestEmail) !== '') {
            $emailCandidates[] = trim($guestEmail);
        }
        $emailCandidates[] = null;

        foreach ($modesToTry as $mode) {
            foreach ($userCandidates as $uid) {
                foreach ($emailCandidates as $email) {
                    try {
                        $result = $couponService->validateAndCalculate(
                            2,
                            $couponCode,
                            $subtotal,
                            $mode,
                            $uid,
                            $email
                        );

                        if (!empty($result['ok'])) {
                            return $result;
                        }
                    } catch (Throwable $e) {
                        // ignore and continue trying fallback combinations
                    }
                }
            }
        }

        return null;
    };

    $couponService = new StoreCouponService();

    if ($couponCode !== '') {
        $couponResult = $resolveCouponPermissive(
            $couponService,
            $couponCode,
            $subtotal,
            $pricingMode,
            $userId ? (int)$userId : null,
            $guestEmail !== '' ? $guestEmail : null
        );

        if (!$couponResult) {
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
    } else {
        $couponCode = '';
    }

    if ($sessionToken === '') {
        $sessionToken = $cartsRepo->generateToken();
    }

    $cart = $cartsRepo->getBySessionToken($sessionToken);

    if (!$cart) {
        $recoveryToken = $cartsRepo->generateToken();

        $ok = $cartsRepo->add([
            'id_owner' => 2,
            'id_user' => $userId,
            'session_token' => $sessionToken,
            'recovery_token' => $recoveryToken,
            'guest_name' => $guestName ?: null,
            'guest_email' => $guestEmail ?: null,
            'guest_phone' => $guestPhone ?: null,
            'city' => $city ?: null,
            'audience_type' => $audience ?: null,
            'meal_style' => $mealStyle ?: null,
            'pricing_mode' => $pricingMode,
            'items_count' => count($cleanItems),
            'meals_count' => $mealsCount,
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
            'audience_type' => $audience ?: null,
            'meal_style' => $mealStyle ?: null,
            'pricing_mode' => $pricingMode,
            'items_count' => count($cleanItems),
            'meals_count' => $mealsCount,
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
            'id_cart' => $cartId,
            'id_product' => $item['id_product'],
            'product_name_snapshot' => $item['product_name_snapshot'],
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
        "membership_enabled" => $membershipEnabled,
        "pricing_mode" => $pricingMode,
        "meals_count" => $mealsCount,
        "subtotal" => $subtotal,
        "discount" => $discount,
        "coupon_code" => $couponCode !== '' ? $couponCode : null,
        "total" => $total,
        "redirect" => \App\Utils\LocationUtils::pathFor("store/checkout")
    ]);

    return '';
});

$router->run();