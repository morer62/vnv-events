<?php

namespace App\Services;

use App\Entity\User;
use App\Repositories\ClientsUsersRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Repositories\UserWorkspacePreferencesRepository;

class UserWorkspaceContextService
{
    private UserWorkspacePreferencesRepository $preferencesRepo;
    private ClientsUsersRepository $clientsUsersRepo;
    private UserInstitutionsRepository $userInstitutionsRepo;
    private InstitutionProfileRepository $institutionProfileRepo;

    public function __construct()
    {
        $this->preferencesRepo = new UserWorkspacePreferencesRepository();
        $this->clientsUsersRepo = new ClientsUsersRepository();
        $this->userInstitutionsRepo = new UserInstitutionsRepository();
        $this->institutionProfileRepo = new InstitutionProfileRepository();
    }

    public function getClientContext(User $user): array
    {
        $companies = $this->clientsUsersRepo->getAssociatedCompaniesForClient((int)$user->getId());
        $preference = $this->preferencesRepo->getByUserId((int)$user->getId());
        $selectedOwnerId = (int)($_SESSION['current_client_owner_id'] ?? ($preference->selected_owner_id ?? 0));

        if (!$this->ownerIsInCompanies($selectedOwnerId, $companies)) {
            $selectedOwnerId = isset($companies[0]) ? (int)$companies[0]->owner_id : (int)$user->getOwner();
        }

        $selectedCompany = $this->findCompanyByOwner($selectedOwnerId, $companies);

        if ($selectedOwnerId > 0) {
            $_SESSION['current_client_owner_id'] = $selectedOwnerId;
            $this->preferencesRepo->saveSelection((int)$user->getId(), 'CLIENT', $selectedOwnerId, $selectedCompany->institution_id ?? null, 'client');
        }

        return [
            'workspaceType' => 'CLIENT',
            'companies' => $companies,
            'selectedOwnerId' => $selectedOwnerId,
            'selectedCompany' => $selectedCompany,
            'hasMultipleCompanies' => count($companies) > 1,
            'canCreateBusinessAccount' => true,
        ];
    }

    public function switchClientCompany(User $user, int $ownerId): bool
    {
        $companies = $this->clientsUsersRepo->getAssociatedCompaniesForClient((int)$user->getId());
        if (!$this->ownerIsInCompanies($ownerId, $companies)) {
            return false;
        }

        $company = $this->findCompanyByOwner($ownerId, $companies);
        $_SESSION['current_client_owner_id'] = $ownerId;

        return $this->preferencesRepo->saveSelection((int)$user->getId(), 'CLIENT', $ownerId, $company->institution_id ?? null, 'client');
    }

    public function getTeamContext(User $user): array
    {
        $institutions = $this->userInstitutionsRepo->getAllUserInstitutions((int)$user->getId());
        $preference = $this->preferencesRepo->getByUserId((int)$user->getId());
        $selectedInstitutionId = (int)($_SESSION['current_institution_id'] ?? ($preference->selected_institution_id ?? 0));

        if (!$this->institutionIsAvailable($selectedInstitutionId, $institutions)) {
            $selectedInstitutionId = isset($institutions[0]) ? (int)$institutions[0]->working_institution_id : 0;
        }

        $selectedInstitution = $selectedInstitutionId > 0 ? $this->institutionProfileRepo->getById($selectedInstitutionId) : null;
        $record = $selectedInstitutionId > 0
            ? $this->userInstitutionsRepo->getUserInstitutionRecord((int)$user->getId(), $selectedInstitutionId)
            : null;

        if ($selectedInstitutionId > 0) {
            $_SESSION['current_institution_id'] = $selectedInstitutionId;
            $_SESSION['current_institution_role'] = $record && $record->secondary_institution_id ? 'employee' : 'owner';
            $this->preferencesRepo->saveSelection(
                (int)$user->getId(),
                'TEAM_MEMBER',
                $selectedInstitution ? (int)$selectedInstitution->id_owner : null,
                $selectedInstitutionId,
                $record->role_name ?? ($_SESSION['current_institution_role'] ?? 'team')
            );
        }

        return [
            'workspaceType' => 'TEAM_MEMBER',
            'institutions' => $institutions,
            'selectedInstitutionId' => $selectedInstitutionId,
            'selectedInstitution' => $selectedInstitution,
            'selectedOwnerId' => $selectedInstitution ? (int)$selectedInstitution->id_owner : (int)$user->getOwner(),
            'relationship' => $record,
            'hasMultipleInstitutions' => count($institutions) > 1,
            'canCreateBusinessAccount' => true,
        ];
    }

    private function ownerIsInCompanies(int $ownerId, array $companies): bool
    {
        foreach ($companies as $company) {
            if ((int)$company->owner_id === $ownerId) {
                return true;
            }
        }

        return false;
    }

    private function findCompanyByOwner(int $ownerId, array $companies): ?object
    {
        foreach ($companies as $company) {
            if ((int)$company->owner_id === $ownerId) {
                return $company;
            }
        }

        return null;
    }

    private function institutionIsAvailable(int $institutionId, array $institutions): bool
    {
        foreach ($institutions as $institution) {
            if ((int)$institution->working_institution_id === $institutionId) {
                return true;
            }
        }

        return false;
    }
}
