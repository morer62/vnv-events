<?php

use App\Repositories\InstitutionProfileRepository;
use App\Repositories\TeamMemberContractTemplatesRepository;
use App\Repositories\TeamMemberContractsRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$loadContext = function (): array {
    $session = LoginService::getSession();
    if (!$session) {
        LocationUtils::redirectInternal('login');
    }

    $memberId = (int)($_GET['id'] ?? $_POST['team_member_id'] ?? 0);
    if ($memberId <= 0) {
        LocationUtils::redirectInternal('panel/planner-hub/management/users');
    }

    $ownerId = $session->getIdOwner();
    $institutionRepo = new InstitutionProfileRepository();
    $institution = $institutionRepo->getByOwner($ownerId);
    $institutionId = $institution ? (int)$institution->id : 0;

    $userRepo = new UserRepository();
    $member = $userRepo->getOneWithoutOwnership(['id' => $memberId]);
    if (!$member || (int)$member->level !== 4) {
        MessageUtil::setMessage('Team member not found.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users');
    }

    $userInstitutionsRepo = new UserInstitutionsRepository();
    if (!$institutionId || !$userInstitutionsRepo->userBelongsToInstitution($memberId, $institutionId)) {
        MessageUtil::setMessage('This team member is not linked to your current institution.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users');
    }

    return [$session, $ownerId, $institution, $member];
};

$router->get(function () use ($loadContext): string {
    [, $ownerId, $institution, $member] = $loadContext();

    $contractRepo = new TeamMemberContractsRepository();
    $templateRepo = new TeamMemberContractTemplatesRepository();

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'member' => $member,
        'institution' => $institution,
        'contracts' => $contractRepo->getAllForMember((int)$member->id, $ownerId),
        'latest_contract' => $contractRepo->getLatestForMember((int)$member->id, $ownerId),
        'templates' => $templateRepo->getAllByOwner($ownerId),
        'template_storage_ready' => $templateRepo->hasStorage(),
    ]);
});

$router->post(function () use ($loadContext): void {
    [$session, $ownerId, , $member] = $loadContext();

    $action = $_POST['action'] ?? '';
    $contractRepo = new TeamMemberContractsRepository();
    $templateRepo = new TeamMemberContractTemplatesRepository();

    if ($action === 'create_template') {
        if (!$templateRepo->hasStorage()) {
            MessageUtil::setMessage('Employee contract template storage is not ready. Apply db/team_member_contract_templates_required.sql first.');
            LocationUtils::reload();
        }

        $title = trim((string)($_POST['template_title'] ?? ''));
        $content = trim((string)($_POST['template_content'] ?? ''));

        if ($title === '' || $content === '') {
            MessageUtil::setMessage('Employee contract title and content are required.');
            LocationUtils::reload();
        }

        $templateRepo->create([
            'id_owner' => $ownerId,
            'title' => $title,
            'content' => $content,
            'status' => 'ACTIVE',
            'created_by' => $session->getId(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        MessageUtil::setMessage('Employee contract template created.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
    }

    if ($action === 'archive_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId <= 0 || !$templateRepo->archiveByIdAndOwner($templateId, $ownerId)) {
            MessageUtil::setMessage('Employee contract template could not be archived.');
            LocationUtils::reload();
        }

        MessageUtil::setMessage('Employee contract template archived.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
    }

    if ($action === 'assign') {
        $templateId = (int)($_POST['contract_template_id'] ?? 0);
        $template = $templateId > 0 ? $templateRepo->getOneByIdAndOwner($templateId, $ownerId) : null;

        if (!$template) {
            MessageUtil::setMessage('Select a valid employee contract template.');
            LocationUtils::reload();
        }

        $token = bin2hex(random_bytes(32));
        $contractRepo->create([
            'id_owner' => $ownerId,
            'team_member_id' => (int)$member->id,
            'contract_template_id' => $templateId,
            'contract_template_version' => date('YmdHis'),
            'assigned_by' => $session->getId(),
            'status' => 'PENDING',
            'source' => 'digital_signature',
            'sign_token' => $token,
            'sign_token_expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'contract_snapshot_html' => $template->content ?? '',
            'contract_snapshot_json' => json_encode([
                'template_id' => $templateId,
                'template_table' => 'team_member_contract_templates',
                'template_title' => $template->title ?? null,
                'assigned_to_email' => $member->email ?? null,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        MessageUtil::setMessage('Contract assigned. The team member can now sign it from their Team Contracts page.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
    }

    if ($action === 'manual_upload') {
        if (empty($_FILES['signed_contract']['tmp_name'])) {
            MessageUtil::setMessage('Upload a signed PDF first.');
            LocationUtils::reload();
        }

        $fileType = (string)($_FILES['signed_contract']['type'] ?? '');
        if ($fileType !== 'application/pdf' && !str_starts_with($fileType, 'image/')) {
            MessageUtil::setMessage('Only PDF or image files can be uploaded as signed contracts.', 'Error', 'error');
            LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
        }

        try {
            $hash = hash_file('sha256', $_FILES['signed_contract']['tmp_name']);
            $filePath = FileUtils::saveFile($_FILES['signed_contract'], 'documents_team_member_contracts/manual');
            if ($filePath === '') {
                throw new \RuntimeException('The uploaded file did not return a public URL.');
            }
        } catch (\Throwable $e) {
            MessageUtil::setMessage('Error uploading signed contract: ' . $e->getMessage(), 'Error', 'error');
            LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
        }

        $latest = $contractRepo->getLatestForMember((int)$member->id, $ownerId);
        $data = [
            'id_owner' => $ownerId,
            'team_member_id' => (int)$member->id,
            'validated_by' => $session->getId(),
            'status' => 'MANUALLY_UPLOADED',
            'source' => 'manual_upload',
            'original_file_path' => $filePath,
            'signed_file_path' => $filePath,
            'generated_pdf_path' => $filePath,
            'signed_pdf_hash' => $hash,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'validated_at' => date('Y-m-d H:i:s'),
            'notes' => $_POST['notes'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($latest && in_array($latest->status, ['PENDING', 'SENT', 'EXPIRED', 'REJECTED'], true)) {
            $contractRepo->updateById((int)$latest->id, $data);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $contractRepo->create($data);
        }

        MessageUtil::setMessage('Manual contract uploaded and validated.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
    }

    if (in_array($action, ['validate', 'reject'], true)) {
        $contractId = (int)($_POST['contract_id'] ?? 0);
        $contract = $contractRepo->getByIdAndOwner($contractId, $ownerId);
        if (!$contract || (int)$contract->team_member_id !== (int)$member->id) {
            MessageUtil::setMessage('Contract not found.');
            LocationUtils::reload();
        }

        $contractRepo->updateById($contractId, [
            'status' => $action === 'validate' ? 'VALIDATED' : 'REJECTED',
            'validated_by' => $action === 'validate' ? $session->getId() : null,
            'validated_at' => $action === 'validate' ? date('Y-m-d H:i:s') : null,
            'notes' => $_POST['notes'] ?? $contract->notes,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        MessageUtil::setMessage($action === 'validate' ? 'Contract validated.' : 'Contract rejected.');
        LocationUtils::redirectInternal('panel/planner-hub/management/users/contracts?id=' . (int)$member->id);
    }

    MessageUtil::setMessage('Invalid action.');
    LocationUtils::reload();
});

$router->run();
