<?php

namespace App\Services;

use App\Entity\User;
use App\Utils\Request;

class ApiAuthService
{
    public static function getToken(?Request $request = null, array $body = []): string
    {
        $headers = $request ? ($request->getHeaders() ?: []) : (function_exists('getallheaders') ? getallheaders() : []);
        $candidates = [
            $headers['Authorization'] ?? null,
            $headers['authorization'] ?? null,
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
            $body['api_token'] ?? null,
            $body['token'] ?? null,
            $_POST['api_token'] ?? null,
            $_POST['token'] ?? null,
            $_GET['api_token'] ?? null,
            $_GET['token'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $token = trim((string)($candidate ?? ''));
            if ($token === '') {
                continue;
            }

            if (strncasecmp($token, 'Bearer ', 7) === 0) {
                $token = trim(substr($token, 7));
            }

            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    public static function bodyFromJsonOrPost(?Request $request = null): array
    {
        $body = $request ? $request->getBody() : json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) {
            $body = [];
        }

        return array_merge($_POST ?: [], $body);
    }

    public static function getAuthenticatedUser(?Request $request = null, array $body = []): ?User
    {
        $token = self::getToken($request, $body);
        if ($token !== '') {
            $user = LoginService::validateToken($token);
            if ($user instanceof User) {
                LoginService::setSession($user);
                return $user;
            }
        }

        $sessionUser = LoginService::getSession();
        return $sessionUser instanceof User ? $sessionUser : null;
    }

    public static function userPayload(User $user): array
    {
        $ownerScopeId = (int)$user->getOwner();

        return [
            'id' => (int)$user->getId(),
            'name' => $user->getName(),
            'lastname' => $user->getLastname(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'phone_validation' => $user->getPhoneValidation(),
            'membership_due_date' => $user->getMembershipDueDate(),
            'membership_type' => $user->getMembershipType(),
            'level' => $user->getLevel(),
            'id_owner' => $ownerScopeId,
            'id_user_business' => $ownerScopeId,
            'owner_scope_id' => $ownerScopeId,
            'raw_id_owner' => $user->getIdOwner(),
            'allow_chat_with_clients' => (int)$user->getAllowChatWithClients(),
            'is_active' => (int)$user->getIsActive(),
        ];
    }

    public static function isValidExpoToken(string $token): bool
    {
        return (bool)preg_match('/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]+\]$/', trim($token));
    }
}
