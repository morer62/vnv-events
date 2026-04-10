<?php

use App\Services\LoginService;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();
 


$router->get(function () {
    $repo = new InstitutionProfileRepository();
    $user = LoginService::getSession();

    $profile = $repo->getByOwner($user->getId());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "profile" => $profile
    ]);
});

$router->post(function () {
    $repo = new InstitutionProfileRepository();
    $user = LoginService::getSession();

    $data = [
        "id_owner" => $user->getId(),
        "company_name" => $_POST["company_name"] ?? "",
        "phone" => $_POST["phone"] ?? "", 
        "email" => $_POST["email"] ?? "",  
        "address_line1" => $_POST["address_line1"] ?? "",
        "city" => $_POST["city"] ?? "",
        "state" => $_POST["state"] ?? "",
        "zip" => $_POST["zip"] ?? "",
        "country" => $_POST["country"] ?? "",
        "payment_method_accepted" => $_POST["payment_method_accepted"] ?? ""
    ];

    $existing = $repo->getByOwner($user->getId());

    

    if (!empty($_FILES["logo"]["tmp_name"])) {
        try {
            $uploadPath = FileUtils::saveFile($_FILES["logo"], "institution_profile/logo");
            $data["logo_path"] = $uploadPath;
        } catch (Exception $e) {
            MessageUtil::setMessage("⚠️ Error uploading logo: " . $e->getMessage());
        }
    }

    if ($existing) {
        $repo->update($data, ["id" => $existing->id]);
        MessageUtil::setMessage("✅ Profile updated.");
    } else {
        $repo->add($data);
        MessageUtil::setMessage("✅ Profile created.");
    }

    LocationUtils::reload();
});

$router->run();
