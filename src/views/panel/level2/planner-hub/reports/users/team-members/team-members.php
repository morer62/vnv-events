<?php

use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    
    // Verificar que sea administrador (nivel 1)
    if ($user->getLevel() != 1) {
        return json_encode([
            'success' => false,
            'message' => 'Access denied. Admin access required.'
        ]);
    }

    // Obtener el ID del usuario desde la URL
    $pathParts = explode('/', $_SERVER['REQUEST_URI']);
    $userId = end($pathParts);
    
    if (!is_numeric($userId)) {
        return json_encode([
            'success' => false,
            'message' => 'Invalid user ID'
        ]);
    }

    try {
        $db = new Connection();
        
        // Obtener miembros del equipo
        $db->query("
            SELECT id, name, lastname, email, phone, level, is_active, membership_due_date
            FROM users 
            WHERE id_owner = :owner_id 
            ORDER BY name ASC, lastname ASC
        ");
        $db->bind(":owner_id", $userId);
        $db->execute();
        $members = $db->fetchAll();
        
        return json_encode([
            'success' => true,
            'members' => $members
        ]);
        
    } catch (Exception $e) {
        return json_encode([
            'success' => false,
            'message' => 'Error loading team members: ' . $e->getMessage()
        ]);
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
