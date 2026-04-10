<?php

use App\Repositories\TipsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $tipsRepo = new TipsRepository();
    $tips = $tipsRepo->getAll();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "tips" => $tips
    ]);
});

$router->post(function () {
    $tipsRepo = new TipsRepository();
    
    $action = $_POST['action'] ?? null;
    
    if ($action === 'create') {
        $percentage = $_POST['percentage'] ?? null;
        
        if (!$percentage || $percentage <= 0) {
            MessageUtil::setMessage("Invalid percentage value.");
            LocationUtils::reload();
        }
        
        $tipsRepo->add([
            'percentage' => $percentage,
            'is_active' => 1
        ]);
        
        MessageUtil::setMessage("Tip created successfully!");
        LocationUtils::reload();
    }
    
    if ($action === 'toggle') {
        $id = $_POST['id'] ?? null;
        $currentStatus = $_POST['current_status'] ?? 0;
        
        if (!$id) {
            MessageUtil::setMessage("Invalid tip ID.");
            LocationUtils::reload();
        }
        
        $newStatus = $currentStatus == 1 ? 0 : 1;
        
        $tipsRepo->update([
            'is_active' => $newStatus
        ], ['id' => $id]);
        
        MessageUtil::setMessage("Tip status updated!");
        LocationUtils::reload();
    }
    
    if ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        
        if (!$id) {
            MessageUtil::setMessage("Invalid tip ID.");
            LocationUtils::reload();
        }
        
        $tipsRepo->delete(['id' => $id]);
        
        MessageUtil::setMessage("Tip deleted successfully!");
        LocationUtils::reload();
    }
});

$router->run();



