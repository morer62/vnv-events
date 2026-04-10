<?php

namespace App\Services;

use App\Entity\User;
use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Utils\LocationUtils;
use DateTime;
use Exception;

class LoginService
{
    private static ?User $cachedSession = null;

    /**
     * Verify credentials without creating session
     * @throws Exception
     */
    public function verifyCredentials($email, $password)
    {
        $userRepository = new UserRepository();

        $user = $userRepository->getOneWithoutOwnership([
            'email' => $email
        ]);

        if ($user == null) {
            throw new Exception("User not found or wrong password");
        }

        if (!HashService::verifyPassword($password, $user->password)) {
            throw new Exception("User not found or wrong password");
        }

        if ((int)$user->is_active === 0) {
            throw new Exception("Your account is inactive.");
        }

        return $user;
    }

    /**
     * @throws Exception
     */
    public function authenticate($email, $password): void
    {
        $userRepository = new UserRepository();
        $userRoleRepository = new UserRolesRepository();

        $user = $this->verifyCredentials($email, $password);
        if ($user->membership_type === 'PAID' && $user->membership_due_date !== null) {
            $dueDate = new DateTime($user->membership_due_date);
            $now = new DateTime();

            if ($dueDate < $now) {
                $userRepository->updateData($user->id, [
                    'membership_type' => 'FREE'
                ]);

                $user->membership_type = 'FREE';
            }
        }

        $permissions = $userRoleRepository->getUserRoleAndPermissions($user->id);

        $userEntity = new User(
            $user->id,
            $user->name,
            $user->lastname,
            $user->email,
            $user->password,
            $user->phone,
            $user->phone_validation,
            $user->membership_due_date,
            $user->level,
            $user->phone_code,
            $user->id_owner,
            $permissions,
            $user->allow_chat_with_clients ?? 0,
            $user->membership_type ?? 'FREE',
            $user->is_active ?? 1
        );

        $this->setSession($userEntity);

        setcookie('vnv_autologin', json_encode([
            'id' => $userEntity->getId(),
            'email' => $userEntity->getEmail()
        ]), [
            'expires' => time() + (86400 * 30 * 6),
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

    }

    public static function setSessionCookie(User $user) {
        $cookie_settings = [
            'expires' => time() + (86400 * 30 * 6),
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ];

        $cookie_payload = [
            'id' => $user->getId(),
            'email' => $user->getEmail()
        ];
        setcookie('vnv_autologin', json_encode($cookie_payload), $cookie_settings);
    }


    public static function setSession(User $user): void {
        $_SESSION['user'] = $user;
        self::$cachedSession = null; // Limpiar caché para forzar recarga
    }

    public static function getSession(): ?User {
    if (self::$cachedSession !== null) {
        return self::$cachedSession;
    }

    if (isset($_SESSION['user'])) {
        self::$cachedSession = $_SESSION['user'];
        return self::$cachedSession;
    }

    // Aquí movemos el flag, solo para el autologin por cookie
    static $cookieChecked = false;
    if ($cookieChecked) {
        return null;
    }
    $cookieChecked = true;

    if (isset($_COOKIE['vnv_autologin'])) {
        $data = json_decode($_COOKIE['vnv_autologin'], true);

        if (isset($data['id'])) {
            $userRepository = new UserRepository();
            $userRoleRepository = new UserRolesRepository();

            $user = $userRepository->getOne(['id' => $data['id']]);

            if ($user && (int)$user->is_active !== 0) {
                $permissions = $userRoleRepository->getUserRoleAndPermissions($user->id);

                $userEntity = new User(
                    $user->id,
                    $user->name,
                    $user->lastname,
                    $user->email,
                    $user->password,
                    $user->phone,
                    $user->phone_validation,
                    $user->membership_due_date,
                    $user->level,
                    $user->phone_code,
                    $user->id_owner,
                    $permissions,
                    $user->allow_chat_with_clients ?? 0,
                    $user->membership_type ?? 'FREE',
                    $user->is_active ?? 1
                );

                $_SESSION['user'] = $userEntity;
                self::$cachedSession = $userEntity;

                return $userEntity;
            }
        }
    }

    return null;
}





    public static function getOwnerAsArray(): array {
        $user = self::getSession();
        return ["id_owner" => $user->getOwner()];
    }

    public static function getUserIdAsArray($append = false): array {
        $user = self::getSession();

        if ($append) {
            return ["id_user" => $user->getId()];
        }

        return [];
    }

    public static function logout(): void {
        unset($_SESSION['user']);
        setcookie('vnv_autologin', '', time() - 3600, '/');
    }

    public static function reloadUserPermissions(?int $institutionId = null): void
    {
        $user = self::getSession();
        
        if (!$user) {
            return;
        }

        $userRepository = new UserRepository();
        $userRoleRepository = new UserRolesRepository();

        $userData = $userRepository->getOne(['id' => $user->getId()]);
        
        if (!$userData) {
            return;
        }

        $permissions = $userRoleRepository->getUserRoleAndPermissions($user->getId(), $institutionId);

        $userEntity = new User(
            $userData->id,
            $userData->name,
            $userData->lastname,
            $userData->email,
            $userData->password,
            $userData->phone,
            $userData->phone_validation,
            $userData->membership_due_date,
            $userData->level,
            $userData->phone_code,
            $userData->id_owner,
            $permissions,
            $userData->allow_chat_with_clients ?? 0,
            $userData->membership_type ?? 'FREE',
            $userData->is_active ?? 1
        );

        self::setSession($userEntity);
        self::$cachedSession = $userEntity;
    }

    public static function verifyPhoneConfirmation($urlViews): bool
    {
        $user = self::getSession();

        if ($user->getLevel() === User::$ADMIN_USER_LEVEL) {
            return true;
        }

        if (
            str_contains(implode("/",$urlViews), "phone/validation") ||
            str_contains(implode("/",$urlViews), "phone/code")
        ) {
            return false;
        }

        if ($user->getPhoneValidation() == 1) {
            return true;
        }

        LocationUtils::redirectInternal("panel/phone/validation");
    }

    /**
     * @throws \DateMalformedStringException
     */
    public static function verifyMembershipDueDate($urlViews): bool {
        $user = self::getSession();

        if (in_array($user->getLevel(), [
            User::$ADMIN_USER_LEVEL,
            User::$TEAM_USER_LEVEL,
            User::$CLIENT_USER_LEVEL,
            User::$MARKETING_USER_LEVEL
        ])) {
            return true;
        }

        if ($user->getMembershipDueDate() != null) {
            $dueDate = new DateTime($user->getMembershipDueDate());
            $now = new DateTime();

            if ($dueDate->getTimestamp() > $now->getTimestamp() ) {
                return true;
            }
        }

        if (str_contains(implode("/",$urlViews), "membership/pay")) {
            return false;
        }

        LocationUtils::redirectInternal("panel/membership/pay");
    }

    /**
     * @throws Exception
     */
    public static function authenticateFromUserDbo($user): void
    {
        if (!$user) {
            throw new Exception("User not found");
        }

        $userRoleRepository = new UserRolesRepository();
        $permissions = $userRoleRepository->getUserRoleAndPermissions($user->id);

        $user = new User(
            $user->id,
            $user->name,
            $user->lastname,
            $user->email,
            $user->password,
            $user->phone,
            $user->phone_validation,
            $user->membership_due_date,
            $user->level,
            $user->phone_code,
            $user->id_owner,
            $permissions,
            $user->allow_chat_with_clients ?? 0,
            $user->membership_type ?? 'FREE',
            $user->is_active ?? 1
        );

        self::setSession($user);
        self::setSessionCookie($user);
    }

   public static function validateToken(string $token): ?User
    {
        $repo = new UserRepository();
        $userRoleRepository = new UserRolesRepository();

        $data = $repo->getOne(["api_token" => $token]);

        if (!$data) {
            return null;
        }

        $permissions = $userRoleRepository->getUserRoleAndPermissions($data->id);

        return new User(
            $data->id,
            $data->name,
            $data->lastname,
            $data->email,
            $data->password,
            $data->phone,
            $data->phone_validation,
            $data->membership_due_date,
            $data->level,
            $data->phone_code,
            $data->id_owner,
            $permissions,
            $data->allow_chat_with_clients ?? 0,
            $data->membership_type,
            $data->is_active
        );
    }

    public static function verifyMany(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $result = $callback();

            if (!$result) {
                break;
            }
        }
    }
}