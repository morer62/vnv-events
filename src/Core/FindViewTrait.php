<?php

namespace App\Core;

trait FindViewTrait
{

    public function getView(string $baseView, string $view): array
    {
        if ($this->isDynamicView($view) ) {
            return $this->getDynamicView($baseView, $view);
        }

        return $this->checkViewOrDefault($baseView, $view);
    }

    public function getDynamicView(string $baseView, string $view): array
    {
        $paths = explode("/", $view);
        $view = "";

        foreach ($paths as $path) {
            if (is_numeric($path) && ctype_digit($path)) {
                $view .= "/[id]";
            } elseif (preg_match('/^AFF[0-9]+$/', $path)) {
                // Códigos de afiliado también usan [id]
                $view .= "/[id]";
            } else {
                $view .= "/$path";
            }
        }

        return $this->checkViewOrDefault($baseView, $view);
    }


    public function isDynamicView($view): bool
    {
        $paths = explode("/", $view);

        foreach ($paths as $path) {
            // Rutas numéricas tradicionales
            if (is_numeric($path) && ctype_digit($path)) {
                return true;
            }
            
            // Rutas de afiliado (códigos que empiezan con AFF)
            if (preg_match('/^AFF[0-9]+$/', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $baseView
     * @param string $view
     * @return array
     */
    public function checkViewOrDefault(string $baseView, string $view): array
    {
        if (file_exists("$baseView/$view.php")) return ["$baseView/$view.php", false];
        if (file_exists("$baseView/$view/index.php")) return ["$baseView/$view/index.php", false];
        if (file_exists("$baseView/$view.old.php")) return ["$baseView/$view.old.php", true];

        return [$this->getNotFoundView(), false];
    }


}
