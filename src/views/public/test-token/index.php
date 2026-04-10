<?php

use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\HashService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
 
$token = '78222b009eaf3d664b9a51d78f3bd756648595c0e546de5bf519c393560cc867';

$repo = new UserRepository();
$user = $repo->getOne(['api_token' => $token]);

if ($user) {
    echo "✅ Usuario encontrado:\n";
    var_dump($user);
} else {
    echo "❌ No se encontró usuario con ese token.";
}
