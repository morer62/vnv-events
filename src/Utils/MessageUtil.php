<?php

namespace App\Utils;

class MessageUtil
{

    public static function setMessage(string $message, $title="Success", $type="success", $x = "bottom", $y = "end"): void {

        $_SESSION['message'] = [
          "message" => $message,
          "title" => $title,
          "type" => $type,
          "x" => $x,
          "y" => $y
        ];
    }

    public static function getMessage(): mixed {
        $message = $_SESSION['message'] ?? null;
        $_SESSION['message'] = null;

        return $message;
    }
}