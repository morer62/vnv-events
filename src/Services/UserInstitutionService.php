<?php

namespace App\Services;

use App\Repositories\UserInstitutionsRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;

class UserInstitutionService
{
    private UserInstitutionsRepository $userInstitutionsRepo;
    private InstitutionProfileRepository $institutionRepo;

    public function __construct()
    {
        $this->userInstitutionsRepo = new UserInstitutionsRepository();
        $this->institutionRepo = new InstitutionProfileRepository();
    }

    public function addUserToInstitution(int $userId, int $institutionId, ?int $roleId = null, ?float $hourlyRate = null, ?string $contractDetail = null): bool
    {
        try {
            $institution = $this->institutionRepo->getById($institutionId);
            if (!$institution) {
                return false;
            }

            if ($this->userInstitutionsRepo->exists($userId, $institutionId)) {
                return false;
            }

            return $this->userInstitutionsRepo->addUserToInstitution($userId, $institutionId, $roleId, $hourlyRate, $contractDetail);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createUserInstitutionRelationship(int $userId, int $institutionId): bool
    {
        return $this->addUserToInstitution($userId, $institutionId);
    }

    public function linkExistingUserToInstitution(int $userId, int $institutionId, ?int $roleId = null, ?float $hourlyRate = null, ?string $contractDetail = null): bool
    {
        try {
            $primaryInstitution = $this->userInstitutionsRepo->getUserPrimaryInstitution($userId);
            if (!$primaryInstitution) {
                $inactivePrimary = $this->userInstitutionsRepo->getInactivePrimaryInstitution($userId);
                if ($inactivePrimary) {
                    $primaryInstitution = $inactivePrimary;
                    $this->userInstitutionsRepo->reactivateUserInstitution($inactivePrimary->id);
                } else {
                    // Si el usuario no tiene ninguna institución primaria (ni activa ni inactiva),
                    // establecer la nueva institución como primaria
                    return $this->userInstitutionsRepo->addUserToInstitution($userId, $institutionId, $roleId, $hourlyRate, $contractDetail);
                }
            }

            $primaryInstitutionId = $primaryInstitution->institution_id;

            // Si la institución que se está vinculando es la misma que la primaria, no hacer nada
            if ($primaryInstitutionId == $institutionId) {
                return true;
            }

            if ($this->userInstitutionsRepo->existsExactRelationship($userId, $primaryInstitutionId, $institutionId)) {
                return false;
            }

            $result = $this->userInstitutionsRepo->linkUserToSecondaryInstitution($userId, $primaryInstitutionId, $institutionId, $roleId, $hourlyRate, $contractDetail);
            
            if (!$result) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCurrentInstitutionContext(int $userId): ?object
    {
        $primaryInstitution = $this->userInstitutionsRepo->getUserPrimaryInstitution($userId);
        if (!$primaryInstitution) {
            return null;
        }

        return $this->institutionRepo->getOne(["id" => $primaryInstitution->institution_id]);
    }

    public function getAvailableInstitutions(int $userId): array
    {
        return $this->userInstitutionsRepo->getAllUserInstitutions($userId);
    }

    public function getCurrentInstitutionOwner(): ?int
    {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if (!$currentInstitutionId) {
            return null;
        }
        
        return $this->institutionRepo->getOwnerId($currentInstitutionId);
    }

    public function switchInstitutionContext(int $userId, int $institutionId): array
    {
        try {
            // Verify user belongs to this institution
            if (!$this->userInstitutionsRepo->userBelongsToInstitution($userId, $institutionId)) {
                return ['success' => false, 'message' => 'User does not belong to this institution'];
            }

            // Update session
            $_SESSION['current_institution_id'] = $institutionId;
            
            // Get institution info for role
            $institution = $this->institutionRepo->getOne(["id" => $institutionId]);
            if ($institution) {
                $_SESSION['current_institution_role'] = 'admin'; // Default role
            }

            return ['success' => true, 'message' => 'Institution context switched successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error switching institution context'];
        }
    }

    public function getUserPrimaryInstitution(int $userId): ?object
    {
        return $this->userInstitutionsRepo->getUserPrimaryInstitution($userId);
    }

    public function getUserAvailableInstitutions(int $userId): array
    {
        return $this->getAvailableInstitutions($userId);
    }

    public function userBelongsToInstitution(int $userId, int $institutionId): bool
    {
        return $this->userInstitutionsRepo->userBelongsToInstitution($userId, $institutionId);
    }
}