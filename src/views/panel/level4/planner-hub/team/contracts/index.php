<?php

use App\Repositories\InstitutionProfileRepository;
use App\Repositories\TeamMemberContractsRepository;
use App\Services\LoginService;
use App\Services\TeamMemberContractPdfGenerator;
use App\Services\UserInstitutionService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$resolveOwner = function ($user): ?int {
    $institutionRepo = new InstitutionProfileRepository();
    $userInstitutionService = new UserInstitutionService();
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;

    if ($currentInstitutionId) {
        $institution = $institutionRepo->getById((int)$currentInstitutionId);
        if ($institution && !empty($institution->id_owner)) {
            return (int)$institution->id_owner;
        }
    }

    $primary = $userInstitutionService->getUserPrimaryInstitution($user->getId());
    if ($primary) {
        $institutionId = $primary->secondary_institution_id ?? $primary->institution_id ?? null;
        if ($institutionId) {
            $institution = $institutionRepo->getById((int)$institutionId);
            if ($institution && !empty($institution->id_owner)) {
                $_SESSION['current_institution_id'] = (int)$institution->id;
                return (int)$institution->id_owner;
            }
        }
    }

    return $user->getOwner();
};

$contractOwnerIsAvailableToUser = function ($user, int $contractOwnerId, ?int $resolvedOwnerId): bool {
    if ($resolvedOwnerId && $contractOwnerId === (int)$resolvedOwnerId) {
        return true;
    }

    if ($user->getOwner() && $contractOwnerId === (int)$user->getOwner()) {
        return true;
    }

    $institutionRepo = new InstitutionProfileRepository();
    $userInstitutionService = new UserInstitutionService();

    foreach ($userInstitutionService->getUserAvailableInstitutions($user->getId()) as $availableInstitution) {
        $institutionId = $availableInstitution->working_institution_id ?? $availableInstitution->institution_id ?? null;
        if (!$institutionId) {
            continue;
        }

        $institution = $institutionRepo->getById((int)$institutionId);
        if ($institution && (int)($institution->id_owner ?? 0) === $contractOwnerId) {
            return true;
        }
    }

    return false;
};

$router->get(function () use ($resolveOwner): string {
    $user = LoginService::getSession();
    $ownerId = $resolveOwner($user);
    $repo = new TeamMemberContractsRepository();
    $contract = $ownerId ? $repo->getLatestForMember($user->getId(), $ownerId) : null;

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'contract' => $contract,
        'pdf_url' => $contract ? ($contract->generated_pdf_path ?: $contract->signed_file_path ?: $contract->original_file_path) : null,
        'contracts_storage_ready' => $repo->hasStorage(),
    ]);
});

$router->post(function () use ($resolveOwner, $contractOwnerIsAvailableToUser): void {
    $user = LoginService::getSession();
    $ownerId = $resolveOwner($user);
    $repo = new TeamMemberContractsRepository();
    $contractId = (int)($_POST['contract_id'] ?? 0);
    $contract = $contractId > 0 ? $repo->getByIdAndMember($contractId, $user->getId()) : null;

    if (!$contract || !$contractOwnerIsAvailableToUser($user, (int)$contract->id_owner, $ownerId ? (int)$ownerId : null)) {
        MessageUtil::setMessage('Contract not found.');
        LocationUtils::reload();
    }

    if (!in_array($contract->status, ['PENDING', 'SENT'], true)) {
        MessageUtil::setMessage('This contract cannot be signed again.');
        LocationUtils::reload();
    }

    if (!empty($contract->sign_token_expires_at) && strtotime($contract->sign_token_expires_at) < time()) {
        $repo->updateById((int)$contract->id, [
            'status' => 'EXPIRED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        MessageUtil::setMessage('This contract link has expired. Please contact your administrator.');
        LocationUtils::reload();
    }

    if (empty($_POST['e_sign_consent'])) {
        MessageUtil::setMessage('Electronic signature consent is required.');
        LocationUtils::reload();
    }

    if (empty($_FILES['signature_image']['tmp_name']) && empty(trim($_POST['typed_initials'] ?? ''))) {
        MessageUtil::setMessage('No signature provided.');
        LocationUtils::reload();
    }

    $signatureImagePath = null;
    $signatureHash = null;
    if (!empty($_FILES['signature_image']['tmp_name'])) {
        $signatureHash = hash_file('sha256', $_FILES['signature_image']['tmp_name']);
        $signatureImagePath = FileUtils::saveFile($_FILES['signature_image'], 'files/team_member_signatures');
    } else {
        $signatureHash = hash('sha256', trim((string)$_POST['typed_initials']));
    }

    try {
        $result = TeamMemberContractPdfGenerator::generateAndSave(
            (int)$contract->id,
            $_POST['user_local_timestamp'] ?? null,
            $signatureImagePath
        );
    } catch (\Throwable $e) {
        error_log('Team member contract PDF error: ' . $e->getMessage());
        MessageUtil::setMessage('Error generating signed contract. Please try again.');
        LocationUtils::reload();
    }

    $repo->updateById((int)$contract->id, [
        'status' => 'SIGNED',
        'signed_file_path' => $result['file_path'],
        'generated_pdf_path' => $result['file_path'],
        'signed_pdf_hash' => $result['hash'],
        'signature_data' => $signatureImagePath ?: trim((string)$_POST['typed_initials']),
        'signature_hash' => $signatureHash,
        'signed_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'signed_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'signed_by_user_id' => $user->getId(),
        'signed_by_email' => $user->getEmail(),
        'signed_at' => date('Y-m-d H:i:s'),
        'sign_token_used_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    MessageUtil::setMessage('Contract signed successfully. You can now clock in.');
    LocationUtils::redirectInternal('panel/planner-hub/team/contracts');
});

$router->run();
