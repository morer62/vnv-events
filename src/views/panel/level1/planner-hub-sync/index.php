<?php

use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

// ✅ Copiar directorios recursivamente
function recursiveCopy(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) mkdir($dst, 0755, true);

    $dir = opendir($src);
    if (!$dir) return false;

    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;

        $srcPath = $src . DIRECTORY_SEPARATOR . $file;
        $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

        if (is_dir($srcPath)) {
            if (!recursiveCopy($srcPath, $dstPath)) return false;
        } else {
            if (!copy($srcPath, $dstPath)) return false;
        }
    }

    closedir($dir);
    return true;
}

// 🧹 Eliminar directorios recursivamente (sin mensajes)
function deleteDirectory(string $dir): bool {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            if (!deleteDirectory($path)) return false;
        } else {
            if (!unlink($path)) return false;
        }
    }

    return rmdir($dir);
}

$router->get(function (): string {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "authorized" => false,
        "synced" => false,
        "message" => null
    ]);
});

$router->post(function (): string {
    $password = $_POST["password"] ?? "";
    $authorized = $password === "12345678";
    $synced = false;
    $message = null;

    if ($authorized) {
        $src = __DIR__ . "/../planner-hub";
        $targets = [
            __DIR__ . "/../../level2/planner-hub",
            __DIR__ . "/../../level3/planner-hub",
            __DIR__ . "/../../level4/planner-hub" // ✅ agregado
        ];

        $synced = true;
        foreach ($targets as $target) {
            deleteDirectory($target);
            if (!recursiveCopy($src, $target)) {
                $synced = false;
                break;
            }
        }

        $message = $synced
            ? "✅ Planner Hub synchronized successfully."
            : "❌ Failed to copy files.";
    } else {
        $message = "❌ Incorrect password.";
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "authorized" => $authorized,
        "synced" => $synced,
        "message" => $message
    ]);
});

$router->run();
