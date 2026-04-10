<?php

use App\Repositories\OrdersFilesRepository;
use App\Repositories\OrdersRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new OrdersFilesRepository();
    $orderRepo = new OrdersRepository();
    $user = LoginService::getSession();
    $orderId = $_GET["id"];

    $order = $orderRepo->getOne([
        "id" => $orderId,
        ...LoginService::getOwnerAsArray()
    ]);

    if (is_null($order)) {
        MessageUtil::setMessage("Order not found");
        LocationUtils::redirectInternal("panel/planner-hub/team/orders/orders");
    }

    $files = $repo->getAllBy([
        "id_order" => $orderId
    ]);

    $files = array_map(function ($file) {

        $mime = new Mimey\MimeTypes();
        $ext = explode(".", $file->file_path)[1];

        if ($ext == "") {
            $file->is_image = false;
            return $file;
        }

        $mimeType = $mime->getMimeType($ext);
        $file->is_image = str_contains($mimeType, "image");
        return $file;
    }, $files);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "files" => $files
    ]);
});



function uploadFile(): never {
    $orderId = $_GET["id"];
    $orderRepo = new OrdersRepository();
    $ordersFilesRepository = new OrdersFilesRepository();
    $user = LoginService::getSession();

    $order = $orderRepo->getOne([
        "id" => $orderId,
        "id_user" => $user->getId()
    ]);

    if (is_null($order)) {
        MessageUtil::setMessage("Order not found");
        LocationUtils::redirectInternal("panel/planner-hub/team/orders/orders");
    }

    if (!FileUtils::hasFile($_FILES, "file")) {
        MessageUtil::setMessage("File not provided");
        LocationUtils::reload();
    }

    $location = "";

    try {
        $location = FileUtils::saveFile($_FILES["file"], "order-files");
    } catch (Exception $e) {
        MessageUtil::setMessage("Error uploading file");
        LocationUtils::reload();
    }


    $ordersFilesRepository->add([
        "id_order" => $orderId,
        "title" => $_POST["title"] ?? "",
        "description" => $_POST["description"] ?? "",
        "file_path" => $location,
        ...LoginService::getOwnerAsArray()
    ]);

    MessageUtil::setMessage("File uploaded successfully");
    LocationUtils::reload();
}

function deleteFile(): never {
    $id = $_POST['id'];
    $ordersFilesRepository = new OrdersFilesRepository();

    $file = $ordersFilesRepository->getOne([
        "id" => $id
    ]);

    if (is_null($file)) {
        MessageUtil::setMessage("File not found");
        LocationUtils::reload();
    }

    $ordersFilesRepository->delete([
        "id" => $id
    ]);

    FileUtils::removeFile($file->file_path);
    MessageUtil::setMessage("File deleted");
    LocationUtils::reload();
}

$router->post(function () {
    if ($_POST["action"] == "delete") {
        deleteFile();
    }

    if ($_POST["action"] == "uploadFile") {
        uploadFile();
    }

    MessageUtil::setMessage("Action not found");
    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
