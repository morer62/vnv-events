<?php

namespace App\Services;

use App\Repositories\UserDeactivationsRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Services\LoginService;

class UserDeactivationService
{
    private UserDeactivationsRepository $deactivationsRepo;
    private UserInstitutionsRepository $userInstitutionsRepo;

    public function __construct()
    {
        $this->deactivationsRepo = new UserDeactivationsRepository();
        $this->userInstitutionsRepo = new UserInstitutionsRepository();
    }

    public function deactivateUserFromInstitution(int $userId, int $institutionId, ?string $reason = null): bool
    {
        try {
            // Deactivate the user-institution relationship
            $deactivated = $this->userInstitutionsRepo->removeUserFromInstitution($userId, $institutionId);
            
            if (!$deactivated) {
                return false;
            }

            // Log the deactivation
            $sessionUser = LoginService::getSession();
            $deactivatedBy = $sessionUser ? $sessionUser->getId() : 0;
            
            $this->deactivationsRepo->addDeactivation($userId, $institutionId, $deactivatedBy, $reason);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function reactivateUserInInstitution(int $userId, int $institutionId): bool
    {
        try {
            return $this->userInstitutionsRepo->reactivateUserInInstitution($userId, $institutionId);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getUserDeactivationHistory(int $userId): array
    {
        return $this->deactivationsRepo->getDeactivationsForUser($userId);
    }

    public function getDeactivationHistoryByDeactivator(int $deactivatorId): array
    {
        return $this->deactivationsRepo->getDeactivationsByDeactivator($deactivatorId);
    }
}
