<?php

namespace App\Repositories;

use App\Entity\AutopaySetting;

class AutopaySettingRepository extends BaseRepository
{
    protected string $table = "autopay_settings";
    protected string $entity = AutopaySetting::class;

    public function __construct() {
        $this->db = new Connection();
    }

    /**
     * Get autopay setting for a user
     */
    public function getByUserId(int $userId): ?AutopaySetting
    {
        $result = $this->getOne(['user_id' => $userId]);
        return $result ? $this->toEntity($result) : null;
    }

    /**
     * Convert stdClass to AutopaySetting entity
     */
    private function toEntity(object $data): AutopaySetting
    {
        $entity = new AutopaySetting();
        $entity->setId($data->id ?? null);
        $entity->setUserId($data->user_id);
        $entity->setEnabled((bool)$data->enabled);
        $entity->setPlanType($data->plan_type);
        $entity->setCreatedAt($data->created_at ?? null);
        $entity->setUpdatedAt($data->updated_at ?? null);
        return $entity;
    }

    /**
     * Check if user has autopay enabled
     */
    public function isAutopayEnabled(int $userId): bool
    {
        $setting = $this->getByUserId($userId);
        return $setting && $setting->isEnabled();
    }

    /**
     * Enable or disable autopay for a user
     */
    public function setAutopayStatus(int $userId, bool $enabled): void
    {
        $this->update(['enabled' => $enabled ? 1 : 0], ['user_id' => $userId]);
    }

    /**
     * Create or update autopay setting
     */
    public function upsertAutopay(int $userId, string $planType, bool $enabled = true): void
    {
        $existing = $this->getByUserId($userId);
        
        if ($existing) {
            $this->update([
                'plan_type' => $planType,
                'enabled' => $enabled ? 1 : 0
            ], ['user_id' => $userId]);
        } else {
            $this->add([
                'user_id' => $userId,
                'plan_type' => $planType,
                'enabled' => $enabled ? 1 : 0
            ]);
        }
    }

    /**
     * Get all users with autopay enabled and membership expired
     */
    public function getUsersDueForRenewal(): array
    {
        $sql = "
            SELECT u.*, aps.* 
            FROM users u 
            INNER JOIN autopay_settings aps ON u.id = aps.user_id 
            WHERE aps.enabled = 1 
            AND u.membership_due_date <= CURDATE()
        ";
        
        $this->db->query($sql);
        $result = $this->db->fetchAll();

        // Debug logging
        $logFile = __DIR__ . '/../../.logs/autopay_' . date('Y-m-d') . '.log';
        
        $this->db->query("SELECT CURDATE() as dt");
        $serverDate = $this->db->fetchOne()->dt;
        file_put_contents($logFile, "[DEBUG] Server CURDATE: $serverDate" . PHP_EOL, FILE_APPEND);

        file_put_contents($logFile, "[DEBUG] SQL: $sql" . PHP_EOL, FILE_APPEND);
        file_put_contents($logFile, "[DEBUG] Result count: " . count($result) . PHP_EOL, FILE_APPEND);
        
        return $result;
    }
}
