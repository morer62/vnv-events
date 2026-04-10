<?php

namespace App\Core;

use App\Entity\User;
use App\Utils\LocationUtils;

trait GetViewTrait
{
    private function getPrivateView(array $urlViews, User $user): array
    {
        $root = LocationUtils::getRootLocation() . "/src";
        $view = implode(DIRECTORY_SEPARATOR, array_slice($urlViews, 1));
        $userLevel = $user->getLevel();
        $baseView = $root . "/views/panel/level$userLevel";
        return $this->getView($baseView, $view);
    }

    private function getPublicView(array $urlViews): array
    {
        $root = LocationUtils::getRootLocation() . "/src";
        $view = implode(DIRECTORY_SEPARATOR, $urlViews);
        $baseView = $root . "/views/" . self::$publicFolderViews;
        return $this->getView($baseView, $view);
    }

    private function getApiViews(array $urlViews): array
    {
        $root = LocationUtils::getRootLocation() . "/src";
        $view = implode(DIRECTORY_SEPARATOR, array_slice($urlViews, 1));
        $baseView = $root . "/views/" . self::$apiFolderViews;

        if (file_exists("$baseView/$view.php")) {
            return ["$baseView/$view.php", false];
        }

        if (file_exists("$baseView/$view/index.php")) {
            return ["$baseView/$view/index.php", false];
        }

        return [$root . "/views/" . self::$apiFolderViews . "/" . self::$notFoundApi, false];
    }
}
