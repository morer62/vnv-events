<?php

use App\Repositories\StoreCouponCustomersRepository;
use App\Repositories\StoreCouponRedemptionsRepository;
use App\Repositories\StoreCouponsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function normalizeCouponDateTime(?string $input): ?string
{
    $value = trim((string)$input);
    if ($value === '') {
        return null;
    }

    // HTML datetime-local sends YYYY-MM-DDTHH:MM
    return str_replace('T', ' ', $value) . ':00';
}

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $couponsRepo = new StoreCouponsRepository();
    $customersRepo = new StoreCouponCustomersRepository();
    $redemptionsRepo = new StoreCouponRedemptionsRepository();
    $userRepo = new UserRepository();

    $coupons = $couponsRepo->getAllByOwner($ownerId, 500);
    foreach ($coupons as $coupon) {
        $coupon->customers = $customersRepo->getByCoupon((int)$coupon->id);
        $coupon->redemptions = $redemptionsRepo->getAllBy(['id_coupon' => (int)$coupon->id], [], 50);
    }

    $clients = $userRepo->getAllBy([
        'id_owner' => $ownerId,
        'level' => 5
    ], ['id', 'name', 'lastname', 'email'], 1000);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "coupons" => $coupons,
        "clients" => $clients ?: []
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $action = trim((string)($_POST['action'] ?? ''));
    $couponsRepo = new StoreCouponsRepository();
    $customersRepo = new StoreCouponCustomersRepository();
    $userRepo = new UserRepository();

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $code = $couponsRepo->normalizeCode((string)($_POST['code'] ?? ''));
        $scope = strtoupper(trim((string)($_POST['scope'] ?? StoreCouponsRepository::SCOPE_GLOBAL)));
        $purchaseMode = strtoupper(trim((string)($_POST['purchase_mode'] ?? StoreCouponsRepository::PURCHASE_MODE_PAYG)));
        $discountType = strtoupper(trim((string)($_POST['discount_type'] ?? StoreCouponsRepository::TYPE_PERCENT)));
        $discountValue = round((float)($_POST['discount_value'] ?? 0), 2);
        $status = strtoupper(trim((string)($_POST['status'] ?? StoreCouponsRepository::STATUS_ACTIVE)));
        $startsAt = normalizeCouponDateTime($_POST['starts_at'] ?? null);
        $expiresAt = normalizeCouponDateTime($_POST['expires_at'] ?? null);
        $maxTotalUses = (int)($_POST['max_total_uses'] ?? 1);
        $maxUsesPerCustomer = (int)($_POST['max_uses_per_customer'] ?? 1);
        $minOrderTotal = round((float)($_POST['min_order_total'] ?? 0), 2);
        $assignedCustomerEmail = strtolower(trim((string)($_POST['assigned_customer_email'] ?? '')));

        if ($code === '' || $discountValue <= 0) {
            MessageUtil::setMessage('Code and discount value are required.');
            LocationUtils::reload();
        }
        if (!in_array($scope, [StoreCouponsRepository::SCOPE_GLOBAL, StoreCouponsRepository::SCOPE_CUSTOMER], true)) {
            MessageUtil::setMessage('Invalid coupon scope.');
            LocationUtils::reload();
        }
        if (!in_array($purchaseMode, [StoreCouponsRepository::PURCHASE_MODE_SUBSCRIPTION, StoreCouponsRepository::PURCHASE_MODE_PAYG], true)) {
            MessageUtil::setMessage('Invalid purchase mode.');
            LocationUtils::reload();
        }
        if (!in_array($discountType, [StoreCouponsRepository::TYPE_PERCENT, StoreCouponsRepository::TYPE_FIXED], true)) {
            MessageUtil::setMessage('Invalid discount type.');
            LocationUtils::reload();
        }
        if (!in_array($status, [StoreCouponsRepository::STATUS_ACTIVE, StoreCouponsRepository::STATUS_INACTIVE], true)) {
            MessageUtil::setMessage('Invalid status.');
            LocationUtils::reload();
        }

        if ($discountType === StoreCouponsRepository::TYPE_PERCENT && $discountValue > 100) {
            MessageUtil::setMessage('Percentage coupon cannot exceed 100%.');
            LocationUtils::reload();
        }

        $payload = [
            'id_owner' => $ownerId,
            'code' => $code,
            'scope' => $scope,
            'purchase_mode' => $purchaseMode,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'status' => $status,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'max_total_uses' => max(0, $maxTotalUses),
            'max_uses_per_customer' => max(0, $maxUsesPerCustomer),
            'min_order_total' => max(0, $minOrderTotal),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($action === 'create') {
            $exists = $couponsRepo->getByOwnerAndCode($ownerId, $code);
            if ($exists) {
                MessageUtil::setMessage('Coupon code already exists.');
                LocationUtils::reload();
            }
            $ok = $couponsRepo->add($payload);
            if ($ok && $scope === StoreCouponsRepository::SCOPE_CUSTOMER && $assignedCustomerEmail !== '') {
                $couponId = $couponsRepo->getLastId();
                $user = $userRepo->getOneWithoutOwnership(['email' => $assignedCustomerEmail]);
                $customersRepo->add([
                    'id_coupon' => $couponId,
                    'id_user' => $user ? (int)$user->id : null,
                    'email' => $assignedCustomerEmail
                ]);
            }
            MessageUtil::setMessage($ok ? 'Coupon created.' : 'Could not create coupon.');
            LocationUtils::reload();
        }

        $coupon = $couponsRepo->getOne(['id' => $id, 'id_owner' => $ownerId]);
        if (!$coupon) {
            MessageUtil::setMessage('Coupon not found.');
            LocationUtils::reload();
        }

        if (strtoupper((string)$coupon->code) !== $code) {
            $exists = $couponsRepo->getByOwnerAndCode($ownerId, $code);
            if ($exists && (int)$exists->id !== $id) {
                MessageUtil::setMessage('Coupon code already exists.');
                LocationUtils::reload();
            }
        }

        $ok = $couponsRepo->update($payload, ['id' => $id]);
        if ($ok && $scope === StoreCouponsRepository::SCOPE_CUSTOMER && $assignedCustomerEmail !== '') {
            $alreadyAssigned = $customersRepo->isAllowedForCoupon($id, null, $assignedCustomerEmail);
            if (!$alreadyAssigned) {
                $user = $userRepo->getOneWithoutOwnership(['email' => $assignedCustomerEmail]);
                $customersRepo->add([
                    'id_coupon' => $id,
                    'id_user' => $user ? (int)$user->id : null,
                    'email' => $assignedCustomerEmail
                ]);
            }
        }
        MessageUtil::setMessage($ok ? 'Coupon updated.' : 'Could not update coupon.');
        LocationUtils::reload();
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $coupon = $couponsRepo->getOne(['id' => $id, 'id_owner' => $ownerId]);
        if (!$coupon) {
            MessageUtil::setMessage('Coupon not found.');
            LocationUtils::reload();
        }
        $nextStatus = strtoupper((string)$coupon->status) === StoreCouponsRepository::STATUS_ACTIVE
            ? StoreCouponsRepository::STATUS_INACTIVE
            : StoreCouponsRepository::STATUS_ACTIVE;
        $ok = $couponsRepo->update([
            'status' => $nextStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);
        MessageUtil::setMessage($ok ? 'Coupon status updated.' : 'Could not update coupon status.');
        LocationUtils::reload();
    }

    if ($action === 'assign_customer') {
        $id = (int)($_POST['id'] ?? 0);
        $email = strtolower(trim((string)($_POST['customer_email'] ?? '')));
        if ($id <= 0 || $email === '') {
            MessageUtil::setMessage('Coupon and customer email are required.');
            LocationUtils::reload();
        }
        $coupon = $couponsRepo->getOne(['id' => $id, 'id_owner' => $ownerId]);
        if (!$coupon) {
            MessageUtil::setMessage('Coupon not found.');
            LocationUtils::reload();
        }
        if (strtoupper((string)$coupon->scope) !== StoreCouponsRepository::SCOPE_CUSTOMER) {
            MessageUtil::setMessage('Only CUSTOMER coupons accept customer assignments.');
            LocationUtils::reload();
        }
        $user = $userRepo->getOneWithoutOwnership(['email' => $email]);
        $ok = $customersRepo->add([
            'id_coupon' => $id,
            'id_user' => $user ? (int)$user->id : null,
            'email' => $email
        ]);
        MessageUtil::setMessage($ok ? 'Customer assigned to coupon.' : 'Could not assign customer.');
        LocationUtils::reload();
    }

    if ($action === 'remove_customer') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            MessageUtil::setMessage('Invalid assignment.');
            LocationUtils::reload();
        }
        $assignment = $customersRepo->getOne(['id' => $assignmentId]);
        if (!$assignment) {
            MessageUtil::setMessage('Assignment not found.');
            LocationUtils::reload();
        }
        $coupon = $couponsRepo->getOne(['id' => (int)$assignment->id_coupon, 'id_owner' => $ownerId]);
        if (!$coupon) {
            MessageUtil::setMessage('Coupon not found.');
            LocationUtils::reload();
        }
        $ok = $customersRepo->delete(['id' => $assignmentId]);
        MessageUtil::setMessage($ok ? 'Customer assignment removed.' : 'Could not remove assignment.');
        LocationUtils::reload();
    }

    MessageUtil::setMessage('Invalid action.');
    LocationUtils::reload();
});

$router->run();

