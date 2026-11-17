<?php

namespace App\Services;

use App\Repositories\NotificationsRepository;
use App\Repositories\OrderProposalRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use InvalidArgumentException;
use RuntimeException;

class OrderProposalWorkflowService
{
    private OrderProposalRepository $proposals;
    private OrderProposalContentService $content;

    public function __construct()
    {
        $this->proposals = new OrderProposalRepository();
        $this->content = new OrderProposalContentService();
    }

    public function getOrCreate(object $order, int $actorId): object
    {
        return $this->proposals->getOrCreate((int)$order->id, (int)$order->id_owner, $actorId);
    }

    public function saveDraft(object $proposal, string $html, ?string $changeNote, int $actorId): int
    {
        if ((string)$proposal->status === 'APPROVED') {
            throw new RuntimeException('This approved proposal must be reopened with a reason before it can be edited.');
        }
        $clean = $this->content->sanitize($html);
        $plain = $this->content->toPlainText($clean);
        if ($plain === '' && !str_contains($clean, '<img')) {
            throw new InvalidArgumentException('Add proposal content before saving.');
        }
        $revisionId = $this->proposals->createRevision(
            (int)$proposal->id,
            $clean,
            $plain,
            $this->cleanReason($changeNote, false),
            $actorId
        );
        $this->proposals->addAudit((int)$proposal->id, $revisionId, 'REVISION_SAVED', $actorId, $changeNote);
        return $revisionId;
    }

    public function sendForApproval(object $proposal, int $actorId): void
    {
        if (empty($proposal->current_revision_id)) {
            throw new RuntimeException('Save the proposal before sending it for approval.');
        }
        if ((string)$proposal->status === 'APPROVED') {
            throw new RuntimeException('This proposal is already approved.');
        }
        // Sending a revision is the authoritative moment when client approval
        // becomes required. This keeps the proposal workflow independent from
        // contract/payment status and also supports older orders whose create
        // form did not have the approval checkbox enabled.
        (new OrdersRepository())->update(
            ['proposal_requires_approval' => 1],
            ['id' => (int)$proposal->id_order]
        );
        $this->proposals->setStatus((int)$proposal->id, 'PENDING_APPROVAL', $actorId);
        $this->proposals->addAudit((int)$proposal->id, (int)$proposal->current_revision_id, 'SENT_FOR_APPROVAL', $actorId);
        $this->notifyClient((int)$proposal->id_order, 'A menu / proposal is ready for your review and signature.');
        $this->emailProposalReady((int)$proposal->id_order);
    }

    /** @return array{file_path:string,hash:string,content:string} */
    public function approve(object $proposal, int $actorId, string $typedInitials, ?array $signatureUpload, ?string $userTimestamp): array
    {
        if (!in_array((string)$proposal->status, ['DRAFT', 'PENDING_APPROVAL'], true)) {
            throw new RuntimeException('Only the current proposal awaiting approval can be signed.');
        }
        if (empty($proposal->current_revision_id)) {
            throw new RuntimeException('The proposal has no revision to approve.');
        }

        $method = 'initials';
        $signatureValue = trim($typedInitials);
        if (!empty($signatureUpload['tmp_name'])) {
            $this->validateImageUpload($signatureUpload, 3 * 1024 * 1024);
            $method = 'upload';
            $signatureValue = FileUtils::saveFile($signatureUpload, 'files/proposal-signatures');
        }
        if ($signatureValue === '') {
            throw new InvalidArgumentException('Type your initials or upload a signature image.');
        }
        if ($method === 'initials' && mb_strlen($signatureValue) > 30) {
            throw new InvalidArgumentException('Typed initials must be 30 characters or fewer.');
        }

        $revision = $this->proposals->getRevision((int)$proposal->current_revision_id);
        if (!$revision || (int)$revision->id_proposal !== (int)$proposal->id) {
            throw new RuntimeException('The proposal revision could not be verified.');
        }

        $pdf = (new OrderProposalPdfGenerator())->generateAndSave($proposal, $revision, $method, $signatureValue, $userTimestamp);
        $signedAt = $userTimestamp && strtotime($userTimestamp)
            ? date('Y-m-d H:i:s', strtotime($userTimestamp))
            : date('Y-m-d H:i:s');
        $this->proposals->addApproval([
            'id_proposal' => (int)$proposal->id,
            'id_revision' => (int)$revision->id,
            'action' => 'APPROVED',
            'actor_user_id' => $actorId,
            'signature_method' => $method,
            'signature_value' => $signatureValue,
            'signed_pdf_path' => $pdf['file_path'],
            'content_hash' => $pdf['hash'],
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            'user_local_timestamp' => $signedAt,
        ]);
        (new OrdersRepository())->update(
            ['proposal_requires_approval' => 1],
            ['id' => (int)$proposal->id_order]
        );
        $this->proposals->setStatus((int)$proposal->id, 'APPROVED', $actorId, (int)$revision->id);
        $this->proposals->addAudit((int)$proposal->id, (int)$revision->id, 'PROPOSAL_APPROVED', $actorId, null, [
            'content_hash' => $pdf['hash'],
            'signed_pdf_path' => $pdf['file_path'],
        ]);
        $this->notifyBoth((int)$proposal->id_order, 'The menu / proposal was approved and signed.');
        $this->emailApprovalCopies((int)$proposal->id_order, $revision, $pdf['content']);
        return $pdf;
    }

    public function revoke(object $proposal, int $actorId, string $reason): void
    {
        if ((string)$proposal->status !== 'APPROVED' || empty($proposal->approved_revision_id)) {
            throw new RuntimeException('Only an approved proposal can be reopened.');
        }
        $reason = $this->cleanReason($reason, true);
        $this->proposals->addApproval([
            'id_proposal' => (int)$proposal->id,
            'id_revision' => (int)$proposal->approved_revision_id,
            'action' => 'REVOKED',
            'actor_user_id' => $actorId,
            'reason' => $reason,
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            'user_local_timestamp' => date('Y-m-d H:i:s'),
        ]);
        $this->proposals->setStatus((int)$proposal->id, 'CHANGES_REQUESTED', $actorId, null, true);
        $this->proposals->addAudit((int)$proposal->id, (int)$proposal->approved_revision_id, 'APPROVAL_REVOKED', $actorId, $reason);
        $this->notifyBoth((int)$proposal->id_order, 'The proposal approval was reopened. Reason: ' . $reason);
    }

    public function uploadImage(array $file): string
    {
        $this->validateImageUpload($file, 6 * 1024 * 1024);
        return FileUtils::saveFile($file, 'files/order-proposals');
    }

    private function validateImageUpload(array &$file, int $maxBytes): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Select a valid image.');
        }
        if ((int)($file['size'] ?? 0) > $maxBytes) {
            throw new InvalidArgumentException('The image is too large.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Only JPG, PNG and WebP images are accepted.');
        }
        $file['type'] = $mime;
    }

    private function cleanReason(?string $reason, bool $required): ?string
    {
        $reason = trim(strip_tags((string)$reason));
        if ($required && mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Enter a reason of at least 5 characters.');
        }
        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The reason cannot exceed 500 characters.');
        }
        return $reason !== '' ? $reason : null;
    }

    private function notifyClient(int $orderId, string $message): void
    {
        $order = (new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId);
        if (!$order) return;
        (new NotificationsRepository())->add([
            'id_user' => (int)$order['id_client'],
            'mensaje' => $message . ' Order VNV-341' . $orderId,
            'link' => LocationUtils::pathFor('panel/planner-hub/orders/orders/proposal?id=' . $orderId),
            'leido' => 0,
        ]);
    }

    private function notifyBoth(int $orderId, string $message): void
    {
        $order = (new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId);
        if (!$order) return;
        $notifications = new NotificationsRepository();
        foreach (array_unique([(int)$order['id_client'], (int)$order['id_owner']]) as $userId) {
            $isClient = $userId === (int)$order['id_client'];
            $notifications->add([
                'id_user' => $userId,
                'mensaje' => $message . ' Order VNV-341' . $orderId,
                'link' => LocationUtils::pathFor(($isClient
                    ? 'panel/planner-hub/orders/orders/proposal?id='
                    : 'panel/planner-hub/management/orders/orders/proposal?id=') . $orderId),
                'leido' => 0,
            ]);
        }
    }

    private function emailApprovalCopies(int $orderId, object $revision, string $pdfContent): void
    {
        try {
            $orderData = (new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId);
            if (!$orderData) return;
            $users = new UserRepository();
            $client = $users->getOne(['id' => (int)$orderData['id_client']]);
            $owner = $users->getOne(['id' => (int)$orderData['id_owner']]);
            $mailer = new EmailService((int)$orderData['id_owner']);
            $subject = 'Signed menu / proposal approval - Order VNV-341' . $orderId;
            $body = '<p>The client approved and signed revision ' . (int)$revision->revision_number . ' of the menu / proposal for Order VNV-341' . $orderId . '.</p><p>A retained PDF copy is attached.</p>';
            // Send the business copy first and isolate recipient failures. A bad
            // or retired client address must never prevent VNV from retaining
            // and receiving its own signed approval copy.
            foreach (array_unique(array_filter([(string)($owner->email ?? ''), (string)($client->email ?? '')])) as $email) {
                try {
                    $mailer->sendEmailWithStringAttachment($email, $subject, $body, $pdfContent, 'VNV-341' . $orderId . '-proposal-approval.pdf');
                } catch (\Throwable $recipientException) {
                    error_log('Proposal approval email copy failed for one recipient: ' . $recipientException->getMessage());
                }
            }
        } catch (\Throwable $exception) {
            // The signed PDF and immutable approval record remain authoritative
            // even if the SMTP provider is temporarily unavailable.
            error_log('Proposal approval email copy failed: ' . $exception->getMessage());
        }
    }

    private function emailProposalReady(int $orderId): void
    {
        try {
            $orderData = (new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId);
            if (!$orderData) return;

            $order = (object)$orderData;
            $client = (new UserRepository())->getOneWithoutOwnership(['id' => (int)$order->id_client]);
            $email = trim((string)($client->email ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return;

            $url = (new OrderProposalAccessService())->urlForOrder($order);
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeName = htmlspecialchars(trim((string)($client->name ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = 'Your menu / proposal is ready - Order VNV-341' . $orderId;
            $body = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#17384a">'
                . '<h2>Your menu / proposal is ready</h2>'
                . '<p>Hello' . ($safeName !== '' ? ' ' . $safeName : '') . ',</p>'
                . '<p>Please review the current proposal for Order <strong>VNV-341' . $orderId . '</strong>. You can approve and sign it from the secure link below.</p>'
                . '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="display:inline-block;background:#0b8f8a;color:#fff;text-decoration:none;padding:13px 22px;border-radius:8px;font-weight:700">Review and sign proposal</a></p>'
                . '<p>If the button does not open, copy this URL into your browser:</p>'
                . '<p style="word-break:break-all"><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
                . '<p>This secure link can also be sent to you directly by the VNV Events team.</p>'
                . '</div>';

            (new EmailService((int)$order->id_owner))->sendSimpleEmail($email, $subject, $body, true);
        } catch (\Throwable $exception) {
            // The dashboard link and approval state remain available even when
            // the SMTP provider is temporarily unavailable.
            error_log('Proposal ready email failed: ' . $exception->getMessage());
        }
    }
}
