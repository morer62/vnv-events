<?php

namespace App\Services;

use App\Repositories\UserEditLogsRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\NotificationsRepository;
use App\Services\LoginService;
use App\Services\EmailService;
use App\Utils\LocationUtils;

class UserEditService
{
    private UserEditLogsRepository $editLogsRepo;
    private UserRepository $userRepo;
    private InstitutionProfileRepository $institutionRepo;
    private NotificationsRepository $notificationsRepo;

    public function __construct()
    {
        $this->editLogsRepo = new UserEditLogsRepository();
        $this->userRepo = new UserRepository();
        $this->institutionRepo = new InstitutionProfileRepository();
        $this->notificationsRepo = new NotificationsRepository();
    }

    public function logUserChanges(int $userId, array $oldData, array $newData): bool
    {
        $changes = $this->calculateChanges($oldData, $newData);
        
        if (empty($changes)) {
            return true;
        }

        $sessionUser = LoginService::getSession();
        $editedBy = $sessionUser ? $sessionUser->getId() : 0;
        
        $this->editLogsRepo->logEdit($userId, $editedBy, [
            'old' => $oldData,
            'new' => $newData,
            'changes' => $changes
        ]);
        
        return true;
    }

    private function calculateChanges(array $oldData, array $newData): array
    {
        $changes = [];
        
        $fieldsToTrack = ['name', 'lastname', 'email', 'phone', 'level', 'hourly_rate'];
        
        foreach ($fieldsToTrack as $field) {
            if (isset($oldData[$field]) && isset($newData[$field])) {
                if ($oldData[$field] != $newData[$field]) {
                    $changes[$field] = [
                        'old' => $oldData[$field],
                        'new' => $newData[$field]
                    ];
                }
            }
        }
        
        return $changes;
    }

    public function getUserEditHistory(int $userId): array
    {
        return $this->editLogsRepo->getEditsByUser($userId);
    }

    public function getPendingEditsForOwner(int $ownerId): array
    {
        return $this->editLogsRepo->getPendingEditsForOwner($ownerId);
    }

    public function revertEdit(int $editLogId): array
    {
        $editLog = $this->editLogsRepo->getEditById($editLogId);
        
        if (!$editLog) {
            return ['success' => false, 'message' => 'Edit log not found.'];
        }

        $user = $this->userRepo->getOne(["id" => $editLog->user_id]);
        
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $sessionUser = LoginService::getSession();
        if (!$sessionUser) {
            return ['success' => false, 'message' => 'Session not found.'];
        }

        if ($user->id_owner != $sessionUser->getIdOwner()) {
            return ['success' => false, 'message' => 'You can only revert edits for users owned by your institution.'];
        }

        if (!isset($editLog->changes['old'])) {
            return ['success' => false, 'message' => 'Original data not found in edit log.'];
        }

        $oldData = $editLog->changes['old'];
        
        $revertData = [];
        foreach ($oldData as $field => $value) {
            $revertData[$field] = $value;
        }

        $this->userRepo->update($revertData, ["id" => $editLog->user_id]);
        $this->editLogsRepo->delete(["id" => $editLogId]);

        return ['success' => true, 'message' => 'Changes have been successfully reverted.'];
    }

    public function logUserEdit(int $userId, int $editedBy, array $originalData, array $newData): bool
    {
        try {
            $this->logUserChanges($userId, $originalData, $newData);
            
            $user = $this->userRepo->getOne(["id" => $userId]);
            $editor = $this->userRepo->getOne(["id" => $editedBy]);
            
            if (!$user || !$editor) {
                return false;
            }

            $owner = $this->userRepo->getOne(["id" => $user->id_owner]);
            if (!$owner) {
                return false;
            }

            $editorInstitution = $this->institutionRepo->getByOwner($editor->id_owner);
            $institutionName = $editorInstitution ? $editorInstitution->company_name : 'Unknown Company';

            $this->sendEditNotificationEmail($owner, $user, $editor, $institutionName, $originalData, $newData);
            
            $this->createEditNotification($owner->id, $user, $editor, $institutionName, $originalData, $newData);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function sendEditNotificationEmail($owner, $user, $editor, $institutionName, $originalData, $newData): bool
    {
        try {
            $changes = $this->calculateChanges($originalData, $newData);
            
            if (empty($changes)) {
                return true;
            }

            $subject = "Profile Changes Made to {$user->name} {$user->lastname}";
            
            $message = "
                <h2>Profile Changes Notification</h2>
                <p>Dear {$owner->name} {$owner->lastname},</p>
                
                <p>This is to inform you that changes have been made to the profile of <strong>{$user->name} {$user->lastname}</strong> ({$user->email}) by <strong>{$editor->name} {$editor->lastname}</strong> from <strong>{$institutionName}</strong>.</p>
                
                <h3>Changes Made:</h3>
                <ul>
            ";

            foreach ($changes as $field => $change) {
                $fieldName = ucfirst(str_replace('_', ' ', $field));
                $message .= "<li><strong>{$fieldName}:</strong> '{$change['old']}' → '{$change['new']}'</li>";
            }

            $message .= "
                </ul>
                
                <p><strong>If you did not authorize these changes, you can:</strong></p>
                <ul>
                    <li>Contact {$institutionName} directly to discuss the changes</li>
                    <li>Revert the changes using our system (if available)</li>
                    <li>Contact our support team for assistance</li>
                </ul>
                
                <p>This notification was sent automatically to keep you informed about your account security.</p>
                
                <p>Best regards,<br>VNV Events Team</p>
            ";

            $emailService = new EmailService();
            return $emailService->sendSimpleEmail($owner->email, $subject, $message);
            
        } catch (\Exception $e) {
            return false;
        }
    }

    private function createEditNotification(int $ownerId, $user, $editor, string $institutionName, array $originalData, array $newData): bool
    {
        try {
            $changes = $this->calculateChanges($originalData, $newData);
            
            if (empty($changes)) {
                return true; 
            }

            $changesList = [];
            foreach ($changes as $field => $change) {
                $fieldName = ucfirst(str_replace('_', ' ', $field));
                $changesList[] = "{$fieldName}: '{$change['old']}' → '{$change['new']}'";
            }
            
            $changesText = implode(', ', $changesList);
            
            $message = "✏️ Profile Changes - {$editor->name} {$editor->lastname} from {$institutionName} made changes to {$user->name} {$user->lastname}: {$changesText}";
            
            $link = LocationUtils::pathFor("/panel/planner-hub/management/users/edit?id={$user->id}");
            
            return $this->notificationsRepo->add([
                "id_user" => $ownerId,
                "mensaje" => $message,
                "link" => $link,
                "leido" => 0
            ]);
            
        } catch (\Exception $e) {
            return false;
        }
    }
}



