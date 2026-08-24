<?php

use App\Repositories\OrderExecutionFilesRepository;
use App\Repositories\OrdersRepository;
use App\Services\LoginService;
use App\Services\NotificationService;
use App\Services\WeeklyExecutionService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$validDate = static function (?string $value): ?string {
    if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
};

$range = static function () use ($validDate): array {
    $today = new DateTimeImmutable('today');
    $monday = $today->modify('monday this week');
    $preset = (string)($_GET['week'] ?? 'current');
    if ($preset === 'previous') {
        $monday = $monday->modify('-7 days');
    } elseif ($preset === 'next') {
        $monday = $monday->modify('+7 days');
    }

    $customStart = $validDate($_GET['start_date'] ?? null);
    $customEnd = $validDate($_GET['end_date'] ?? null);
    if ($customStart && $customEnd && $customStart <= $customEnd) {
        return [$customStart, $customEnd, 'custom'];
    }
    return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'), $preset];
};

$router->get(function () use ($range) {
    $user = LoginService::getSession();
    if ((int)$user->getLevel() !== 1) {
        LocationUtils::redirectInternal('panel/home');
    }
    [$startDate, $endDate, $preset] = $range();
    $events = (new WeeklyExecutionService())->listReadyEvents((int)$user->getOwner(), $startDate, $endDate);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'events' => $events,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'weekPreset' => $preset,
    ]);
});

$router->post(function () use ($range) {
    $user = LoginService::getSession();
    if ((int)$user->getLevel() !== 1) {
        LocationUtils::redirectInternal('panel/home');
    }
    [$startDate, $endDate, $preset] = $range();
    $returnQuery = $preset === 'custom'
        ? '?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate)
        : '?week=' . urlencode($preset);

    $orderId = (int)($_POST['order_id'] ?? 0);
    $order = (new OrdersRepository())->getOne([
        'id' => $orderId,
        'id_owner' => (int)$user->getOwner(),
    ]);
    if (!$order) {
        MessageUtil::setMessage('This order is not available for the current business account.', 'Event Execution', 'error');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_file') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $files = new OrderExecutionFilesRepository();
        $file = $files->getOne([
            'id' => $fileId,
            'id_order' => $orderId,
            'id_owner' => (int)$user->getOwner(),
        ]);
        if (!$file) {
            MessageUtil::setMessage('Execution file not found.', 'Event Execution', 'error');
            LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
        }
        if (!$files->delete(['id' => $fileId])) {
            MessageUtil::setMessage('The execution file could not be deleted.', 'Event Execution', 'error');
            LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
        }
        FileUtils::removeFile((string)$file->file_path);
        MessageUtil::setMessage('Execution file deleted successfully.');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
    }

    if ($action !== 'upload_file') {
        MessageUtil::setMessage('Unsupported action.', 'Event Execution', 'error');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
    }

    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160 || !FileUtils::hasFile($_FILES, 'file')) {
        MessageUtil::setMessage('Enter a file name and select a file.', 'Event Execution', 'error');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
    }
    $upload = $_FILES['file'];
    $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $mime = is_uploaded_file($upload['tmp_name'] ?? '')
        ? (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name'])
        : '';
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !in_array($mime, $allowed, true) || (int)($upload['size'] ?? 0) > 25 * 1024 * 1024) {
        MessageUtil::setMessage('Use a PDF, Word document or image up to 25 MB.', 'Event Execution', 'error');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
    }

    try {
        $upload['type'] = $mime;
        $location = FileUtils::saveFile($upload, 'order-files');
        $saved = (new OrderExecutionFilesRepository())->add([
            'id_order' => $orderId,
            'title' => $title,
            'file_path' => $location,
            'id_owner' => (int)$user->getOwner(),
            'id_uploaded_by' => (int)$user->getId(),
        ]);
        if (!$saved) {
            FileUtils::removeFile($location);
            throw new RuntimeException('The execution-file database record could not be saved.');
        }
        NotificationService::sendToUsers(
            array_values(array_unique([(int)$order->id_client, (int)$order->id_owner])),
            'New execution file',
            'A new execution file was added to Order VNV 341' . $orderId . '.'
        );
        MessageUtil::setMessage('Execution file uploaded successfully.');
    } catch (Throwable $e) {
        MessageUtil::setMessage('The file could not be uploaded. Please try again.', 'Event Execution', 'error');
    }
    LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/execution' . $returnQuery);
});

$router->run();
