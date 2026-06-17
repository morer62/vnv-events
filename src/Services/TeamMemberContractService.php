<?php

namespace App\Services;

use App\Repositories\Connection;

class TeamMemberContractService
{
    private Connection $db;
    private static ?bool $tableExists = null;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function isClockInAllowed(int $teamMemberId, int $ownerId): bool
    {
        return (bool)$this->getClockContractStatus($teamMemberId, $ownerId)['allowed'];
    }

    public function getClockContractStatus(int $teamMemberId, int $ownerId): array
    {
        if (!$this->hasStorage()) {
            return [
                'allowed' => true,
                'status' => 'NOT_REQUIRED',
            ];
        }

        $this->db->query("
            SELECT status, signed_at, validated_at, uploaded_at
            FROM team_member_contracts
            WHERE team_member_id = :team_member_id
              AND id_owner = :id_owner
            ORDER BY COALESCE(validated_at, signed_at, uploaded_at, updated_at, created_at) DESC
            LIMIT 1
        ");
        $this->db->bind(':team_member_id', $teamMemberId);
        $this->db->bind(':id_owner', $ownerId);

        $contract = $this->db->fetchOne();
        if (!$contract) {
            return [
                'allowed' => false,
                'status' => 'NOT_ASSIGNED',
            ];
        }

        $status = (string)$contract->status;

        return [
            'allowed' => in_array($status, ['SIGNED', 'VALIDATED', 'MANUALLY_UPLOADED'], true),
            'status' => $status,
            'signed_at' => $contract->signed_at ?? null,
            'validated_at' => $contract->validated_at ?? null,
            'uploaded_at' => $contract->uploaded_at ?? null,
        ];
    }

    private function hasStorage(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            $this->db->query("SHOW TABLES LIKE 'team_member_contracts'");
            self::$tableExists = (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }
}
