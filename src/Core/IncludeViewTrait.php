<?php

namespace App\Core;

trait IncludeViewTrait {

    private function includeViewAndExit(array $payload): never
    {
        [$view, $isLegacy] = $payload;
        if (!$isLegacy) {
            include $view;
            exit(0);
        }

        $_SESSION["includeView"] = $view;
        include __DIR__ . "/views/templates.old/public.php";
        exit(0);
    }

    private function includeAdminViewAndExit(array $payload): never
    {
        [$view, $isLegacy] = $payload;
        if (!$isLegacy) {
            include $view;
            exit(0);
        }

        $_SESSION["includeView"] = $view;
        include __DIR__ . "/views/templates.old/admin.php";
        exit(0);
    }
}