<?php

namespace App\Core;

use App\Entity\User;
use App\Utils\LocationUtils;

trait GetViewTrait
{
    private function getPrivateView(array $urlViews, User $user): array
    {
        $root = LocationUtils::getRootLocation() . "/src";
        $privateViews = array_slice($urlViews, 1);
        if ((int)$user->getLevel() === 1 && ($privateViews[0] ?? null) === 'music-sessions') {
            $privateViews[0] = 'multimedia-sessions';
        }

        $view = implode(DIRECTORY_SEPARATOR, $privateViews);
        $userLevel = $user->getLevel();
        $baseView = $root . "/views/panel/level$userLevel";
        $resolved = $this->getView($baseView, $view);

        if ($userLevel !== 1 && $resolved[0] === $this->getNotFoundView()) {
            return $this->getView($root . "/views/panel/level1", $view);
        }

        return $resolved;
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
