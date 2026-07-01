<?php

namespace App\Repositories;

class UserRepository extends BaseRepository
{

    public function __construct() {
        $this->table = "users";
        $this->db = new Connection();
    }

    public function getByGoogleIdorEmail($googleId, $email): object|bool
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE google_id = :google_id OR email = :email");
        $this->db->bind(":google_id", $googleId);
        $this->db->bind(":email", $email);
        return $this->db->fetchOne();
    }

    public function updateByEmail(string $email, array $fields): void
    {
        $setParts = [];
        foreach ($fields as $key => $value) {
            $setParts[] = "$key = :$key";
        }
        $setQuery = implode(", ", $setParts);

        $this->db->query("UPDATE users SET $setQuery WHERE email = :email");
        foreach ($fields as $key => $value) {
            $this->db->bind(":$key", $value);
        }
        $this->db->bind(":email", $email);
        $this->db->execute();
    }

    public function getConfirmedMembersForOrder(int $orderId): array
    {
        $sql = "
            SELECT u.*
            FROM users u
            INNER JOIN orders_staff_invites osi ON osi.id_user = u.id
            WHERE osi.id_order = :orderId AND osi.is_confirmed = 1
        ";

        $this->db->query($sql);
        $this->db->bind(":orderId", $orderId);
        return $this->db->fetchAll();
    }

    public function getConfirmedMembersForSuborder(int $suborderId): array
    {
        $sql = "
            SELECT u.*
            FROM users u
            INNER JOIN orders_suborder_staff_invites ossi ON ossi.id_user = u.id
            WHERE ossi.id_suborder = :suborderId AND ossi.is_confirmed = 1
        ";

        $this->db->query($sql);
        $this->db->bind(":suborderId", $suborderId);
        return $this->db->fetchAll();
    }



    public function getAllClientsWithoutOwner(): array
    {
        $query = "SELECT * FROM users WHERE level = 5  AND is_active = 1";
        $this->db->query($query);
        return $this->db->fetchAll();
    }


    public function getAllByOwner(int $ownerId): array
    { 
        $this->db->query("SELECT * FROM {$this->table} WHERE id_owner = :owner_id AND is_active = 1");

        $this->db->bind(":owner_id", $ownerId);
        return $this->db->fetchAll();
    }

    public function updateData(int $id, array $fields): void
    {
        $setParts = [];
        foreach ($fields as $key => $value) {
            $setParts[] = "$key = :$key";
        }
        $setQuery = implode(", ", $setParts);

        $this->db->query("UPDATE users SET $setQuery WHERE id = :id");
        foreach ($fields as $key => $value) {
            $this->db->bind(":$key", $value);
        }
        $this->db->bind(":id", $id);
        $this->db->execute();
    }

    public function updateApiToken(int $id, string $token): void
    {
        $this->db->query("UPDATE users SET api_token = :token WHERE id = :id");
        $this->db->bind(":token", $token);
        $this->db->bind(":id", $id);
        $this->db->execute();
    }

    public function updateExpoToken(int $userId, string $token): void
    {

        $this->db->query("UPDATE users SET expo_token = :token WHERE id = :id");
        $this->db->bind(":token", $token);
        $this->db->bind(":id",  $userId);
        $this->db->execute();
    }

    public function clearExpoToken(int $userId): void
    {
        $this->db->query("UPDATE users SET expo_token = NULL WHERE id = :id");
        $this->db->bind(":id", $userId);
        $this->db->execute();
    }

    public function getMobileAppNotificationRecipients(): array
    {
        $this->db->query("
            SELECT id, name, lastname, email, expo_token
            FROM users
            WHERE is_active = 1
              AND expo_token IS NOT NULL
              AND TRIM(expo_token) != ''
            ORDER BY id DESC
        ");

        return $this->db->fetchAll();
    }



    public function getOneWithoutOwnership(array $criteriaVals, array $columns = []): ?object
    {
        try {
            $columnsSQL = empty($columns) ? "*" : implode(", ", $columns);
            $keys = array_keys($criteriaVals);
            $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));
            $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where LIMIT 1";

            $this->db->query($query);
            foreach ($criteriaVals as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            $result = $this->db->fetchOne();
            return !$result ? null : $result;
        } catch (\PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return null;
        }
    }

 
    public function filterBy(array $filters): array
    {
        $sql = "SELECT * FROM users WHERE id_owner = :id_owner";
        $params = [
            ":id_owner" => $filters["id_owner"],
        ];

        if (!empty($filters["name"])) {
            $sql .= " AND (name LIKE :name OR lastname LIKE :name)";
            $params[":name"] = "%" . $filters["name"] . "%";
        }

        if (!empty($filters["email"])) {
            $sql .= " AND email LIKE :email";
            $params[":email"] = "%" . $filters["email"] . "%";
        }

        if (!empty($filters["level"]) && $filters["level"] !== "all") {
            $sql .= " AND level = :level";
            $params[":level"] = $filters["level"];
        }

        $sql .= " AND is_active = 1";

        $sql .= " ORDER BY id DESC";

        

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return $this->db->fetchAll();
    }


    public function updateMembershipAndRegisterPayment(int $userId, string $newDueDate, float  $amount): void
    {
        // 1. Actualizar la membresía
        $this->db->query("UPDATE users SET membership_due_date = :due, membership_type = 'PAID' WHERE id = :id");
        $this->db->bind(":due", $newDueDate);
        $this->db->bind(":id", $userId);
        $this->db->execute();

        // 2. Guardar el pago en payments_all
        $this->saveMembershipPaymentToAll($userId, floatval($amount), $newDueDate);
    }

    private function saveMembershipPaymentToAll(int $userId, float $amount, string $renewalDate, int $planId = 1): void
    {
        $paymentDate = (new \DateTime())->format("Y-m-d");

        $db = new Connection();
        $db->query("INSERT INTO payments_all 
            (user_id, concept, concept_id, id_membership_plan, payment_date, renewal, total, status, reference)
            VALUES (:user_id, 'Membership', :concept_id, :plan_id, :payment_date, :renewal, :total, 'ACTIVE', :ref)");

        $db->bind(":user_id", $userId);
        $db->bind(":concept_id", $userId);
        $db->bind(":plan_id", $planId);
        $db->bind(":payment_date", $paymentDate);
        $db->bind(":renewal", $renewalDate);
        $db->bind(":total", $amount);
        $db->bind(":ref", "Membership payment");

        $db->execute();

        // Crear comisión de afiliado si corresponde
        try {
            $affiliateService = new \App\Services\AffiliateService();
            $transactionType = $planId == 1 ? 'membership_monthly' : 'membership_annual';
            $affiliateService->createCommission($userId, $transactionType, $amount, "membership_{$userId}_" . time(), null, null);
        } catch (\Exception $e) {
            error_log("Error creating affiliate commission for membership payment: " . $e->getMessage());
        }
    }


   public function delete_level_5 ( $where): void
    {
        $id = $where["id"] ?? null;
        if (!$id) return;

        // Obtener el usuario a eliminar
        $this->db->query("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $this->db->bind(":id", $id);
        $user = $this->db->fetchOne();

        if ($user && (int)$user->level === 5) {
            // Si es cliente (nivel 5), solo eliminar la asociación
            $clientsUsersRepo = new \App\Repositories\ClientsUsersRepository();
            $currentUser = \App\Services\LoginService::getSession();

            $clientsUsersRepo->deleteRelation($user->id, $currentUser->getId());
            return;
        }

        // Si no es cliente, eliminar completamente
        // parent::delete($where);
        $this->updateData($id, ['is_active' => 0]);

    }


        public function exists(int $clientId, int $ownerId): bool
        {
            $this->db->query("SELECT COUNT(*) as total FROM {$this->table} WHERE client_id = :client AND id_owner_asociated = :owner");
            $this->db->bind(":client", $clientId);
            $this->db->bind(":owner", $ownerId);

            return (int) $this->db->fetchOne()["total"] > 0;
        }

        public function searchClientsByEmail(string $query): array
        {
            $this->db->query("
                SELECT * FROM users
                WHERE email LIKE :query AND level = 5 AND is_active = 1
                LIMIT 10
            ");
            $this->db->bind(":query", "%" . $query . "%");
            return $this->db->fetchAll();
        }

 


        public function getAllAssociatedClients(int $ownerId): array {
            $sql = "
                SELECT u.*
                FROM users u
                INNER JOIN clients_users AS cu ON cu.client_id = u.id
                WHERE cu.id_owner_asociated  = :owner AND u.is_active = 1
            ";
            $this->db->query($sql);
            $this->db->bind(":owner", $ownerId);
            return $this->db->fetchAll();
        }

 
    public function getByIdEvenIfAssociated(int $id): ?object
    {
        $session = \App\Services\LoginService::getSession();
        $me = \App\Services\LoginService::getSession();
        $ownerId = $me->getOwner();

        $this->db->query("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $this->db->bind(":id", $id);
        $user = $this->db->fetchOne();
        if (!$user) return null;

        // Si no es cliente, permitir directamente
        if ((int)$user->level !== 5) {
            return $user;
        }

        // Validar que el cliente le pertenece o esté asociado
        if ((int)$user->id_owner === $ownerId) {
            return $user;
        }

        $clientsUsersRepo = new \App\Repositories\ClientsUsersRepository();
        $associatedIds = $clientsUsersRepo->getClientIdsByUserId($ownerId);

        if (in_array((int)$user->id, $associatedIds)) {
            return $user;
        }

        return null;
    }



    public function getAllFlexible(array $filters): array
    {
        $sql = "SELECT * FROM users  WHERE is_active = 1";
        $params = [];

        foreach ($filters as $key => $value) {
            if (str_ends_with($key, "!=")) {
                $field = trim(str_replace("!=", "", $key));
                $paramName = $field . "_not";
                $sql .= " AND `$field` != :$paramName";
                $params[$paramName] = $value;
            } elseif (str_ends_with($key, " IN")) {
                $field = trim(str_replace(" IN", "", $key));
                $placeholders = [];
                foreach ($value as $index => $v) {
                    $ph = "{$field}_in_$index";
                    $placeholders[] = ":$ph";
                    $params[$ph] = $v;
                }
                $sql .= " AND `$field` IN (" . implode(",", $placeholders) . ")";
            } else {
                $paramName = $key;
                $sql .= " AND `$key` = :$paramName";
                $params[$paramName] = $value;
            }
        }

        $this->db->query($sql);
        foreach ($params as $key => $val) {
            $this->db->bind(":$key", $val);
        }

        return $this->db->fetchAll();
    }



    public function findInactiveByEmail(string $email): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE email = :email AND is_active = 0 LIMIT 1");
        $this->db->bind(":email", $email);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function reactivateUser(int $userId, int $newOwnerId, array $extraFields = []): void
    {
        $setParts = ["is_active = 1", "id_owner = :id_owner"];
        foreach ($extraFields as $key => $value) {
            $setParts[] = "$key = :$key";
        }
        $setQuery = implode(", ", $setParts);

        $this->db->query("UPDATE {$this->table} SET $setQuery WHERE id = :id");
        $this->db->bind(":id_owner", $newOwnerId);
        foreach ($extraFields as $key => $value) {
            $this->db->bind(":$key", $value);
        }
        $this->db->bind(":id", $userId);
        $this->db->execute();
    }



    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn($v) => !is_null($v))));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, name, lastname, email, phone FROM users WHERE id IN ($placeholders)";

        $this->db->query($sql);
        foreach ($ids as $i => $id) {
            $this->db->bind($i + 1, $id);
        }

        $rows = $this->db->fetchAll(); 
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->id] = $r; // mapa por id
        }
        return $map;
    }


    public function getProfileNudge(int $userId, int $level): array
    {
        // level: 2 = venue, 3 = vendor/service
        $type        = ($level === 2) ? 'venue' : 'service';
        $table       = ($type === 'venue') ? 'venues' : 'service';
        $photosTable = ($type === 'venue') ? 'venue_photos' : 'service_photos';
        $photosFK    = ($type === 'venue') ? 'venue_id' : 'service_id';
        $priceEnvKey = ($type === 'venue') ? 'VENUE_PAYMENT_AMOUNT' : 'SERVICE_PAYMENT_AMOUNT';

        // Precio desde .env (fallback a 0 si no existe)
        $price = isset($_ENV[$priceEnvKey]) ? floatval($_ENV[$priceEnvKey]) : 0.0;

        // 1) Buscar perfil del usuario
        $this->db->query("SELECT * FROM `$table` WHERE user_id = :uid LIMIT 1");
        $this->db->bind(':uid', $userId);
        $profile = $this->db->fetchOne();

        if (!$profile) {
            return [
                'should_show' => true,
                'reason'      => 'no_profile',
                'type'        => $type,
                'price'       => $price,
                'profile_id'  => null,
                'approved'    => false,
                'active'      => false,
                'has_photos'  => false,
            ];
        }

        // 2) Estado y vigencia
        $approved = (isset($profile->status) && strtoupper($profile->status) === 'APPROVED');

        $active = false;
        if (!empty($profile->expiration_date)) {
            $this->db->query("SELECT (CASE WHEN :exp >= CURDATE() THEN 1 ELSE 0 END) AS ok");
            $this->db->bind(':exp', $profile->expiration_date);
            $active = (bool) $this->db->fetchOne()->ok;
        }

        // 3) Fotos
        $this->db->query("SELECT COUNT(*) AS total FROM `$photosTable` WHERE `$photosFK` = :pid");
        $this->db->bind(':pid', (int) $profile->id);
        $hasPhotos = (int)$this->db->fetchOne()->total > 0;

        // 4) Motivo del nudge
        $reason = null;
        if (!$approved || !$active) {
            $reason = 'not_active'; // incluye no aprobado o vencido/no pagado
        } elseif (!$hasPhotos) {
            $reason = 'no_photos';
        }

        return [
            'should_show' => (bool)$reason,
            'reason'      => $reason,
            'type'        => $type,
            'price'       => $price,
            'profile_id'  => (int)$profile->id,
            'approved'    => $approved,
            'active'      => $active,
            'has_photos'  => $hasPhotos,
        ];
    }

    /**
     * Get users with expired memberships
     */
    public function getExpiredMembershipUsers(string $searchTerm = '', int $limit = 0, int $offset = 0): array
    {
        $whereClause = "WHERE u.is_active = 1 
            AND u.level IN (2, 3) 
            AND u.membership_due_date IS NOT NULL 
            AND u.membership_due_date < CURDATE()";
        
        $params = [];
        
        if (!empty($searchTerm)) {
            $whereClause .= " AND (u.name LIKE :search OR u.lastname LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%$searchTerm%";
        }
        
        $limitClause = '';
        if ($limit > 0) {
            $limitClause = "LIMIT $limit OFFSET $offset";
        }
        
        $this->db->query("
            SELECT u.*, 
                   CASE 
                       WHEN u.level = 2 THEN 'Venue Owner'
                       WHEN u.level = 3 THEN 'Vendor'
                       ELSE CONCAT('Level ', u.level)
                   END as user_type
            FROM users u 
            $whereClause
            ORDER BY u.membership_due_date ASC
            $limitClause
        ");
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        return $this->db->fetchAll();
    }

    /**
     * Get users with memberships expiring soon (within 7 days)
     */
    public function getExpiringSoonMembershipUsers(string $searchTerm = '', int $limit = 0, int $offset = 0): array
    {
        $whereClause = "WHERE u.is_active = 1 
            AND u.level IN (2, 3) 
            AND u.membership_due_date IS NOT NULL 
            AND u.membership_due_date >= CURDATE()
            AND u.membership_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        
        $params = [];
        
        if (!empty($searchTerm)) {
            $whereClause .= " AND (u.name LIKE :search OR u.lastname LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%$searchTerm%";
        }
        
        $limitClause = '';
        if ($limit > 0) {
            $limitClause = "LIMIT $limit OFFSET $offset";
        }
        
        $this->db->query("
            SELECT u.*, 
                   CASE 
                       WHEN u.level = 2 THEN 'Venue Owner'
                       WHEN u.level = 3 THEN 'Vendor'
                       ELSE CONCAT('Level ', u.level)
                   END as user_type
            FROM users u 
            $whereClause
            ORDER BY u.membership_due_date ASC
            $limitClause
        ");
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        return $this->db->fetchAll();
    }

    /**
     * Get count of users with expired memberships
     */
    public function getExpiredMembershipUsersCount(string $searchTerm = ''): int
    {
        $whereClause = "WHERE u.is_active = 1 
            AND u.level IN (2, 3) 
            AND u.membership_due_date IS NOT NULL 
            AND u.membership_due_date < CURDATE()";
        
        $params = [];
        
        if (!empty($searchTerm)) {
            $whereClause .= " AND (u.name LIKE :search OR u.lastname LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%$searchTerm%";
        }
        
        $this->db->query("
            SELECT COUNT(*) as total
            FROM users u 
            $whereClause
        ");
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        $result = $this->db->fetchOne();
        return (int) $result->total;
    }

    /**
     * Get count of users with memberships expiring soon
     */
    public function getExpiringSoonMembershipUsersCount(string $searchTerm = ''): int
    {
        $whereClause = "WHERE u.is_active = 1 
            AND u.level IN (2, 3) 
            AND u.membership_due_date IS NOT NULL 
            AND u.membership_due_date >= CURDATE()
            AND u.membership_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        
        $params = [];
        
        if (!empty($searchTerm)) {
            $whereClause .= " AND (u.name LIKE :search OR u.lastname LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%$searchTerm%";
        }
        
        $this->db->query("
            SELECT COUNT(*) as total
            FROM users u 
            $whereClause
        ");
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        $result = $this->db->fetchOne();
        return (int) $result->total;
    }

    /**
     * Get users by institution ID using the new user_institutions table
     */
    public function getUsersByInstitution(int $institutionId): array
    {
        $sql = "
            SELECT u.*, ui.is_active as institution_active
            FROM users u
            JOIN user_institutions ui ON u.id = ui.user_id
            WHERE ui.institution_id = :institution_id
            AND ui.is_active = 1
            ORDER BY ui.created_at DESC
        ";

        $this->db->query($sql);
        $this->db->bind(":institution_id", $institutionId);
        
        return $this->db->fetchAll();
    }

    /**
     * Filter users by institution using the new user_institutions table
     */
    public function filterByInstitution(array $filters): array
    {
        if (!isset($filters['institution_id'])) {
            return [];
        }

        $institutionId = $filters['institution_id'];
        $whereConditions = ["u.is_active = 1"];
        $params = [':institution_id' => $institutionId];

        // Name filter
        if (isset($filters['name'])) {
            $whereConditions[] = "(u.name LIKE :name OR u.lastname LIKE :name)";
            $params[':name'] = "%{$filters['name']}%";
        }

        // Email filter
        if (isset($filters['email'])) {
            $whereConditions[] = "u.email LIKE :email";
            $params[':email'] = "%{$filters['email']}%";
        }

        // Level filter
        if (isset($filters['level'])) {
            $whereConditions[] = "u.level = :level";
            $params[':level'] = $filters['level'];
        }

        $whereClause = implode(" AND ", $whereConditions);

        $sql = "
            SELECT DISTINCT u.*, 
                   CASE 
                       WHEN ui.institution_id = :institution_id AND ui.secondary_institution_id IS NULL THEN 'primary'
                       WHEN ui.secondary_institution_id = :institution_id THEN 'secondary'
                   END as institution_relationship,
                   ip.company_name as institution_name,
                   ui.is_active as institution_active,
                   ui.id as user_institution_id
            FROM users u
            JOIN user_institutions ui ON u.id = ui.user_id
            LEFT JOIN institution_profile ip ON ui.institution_id = ip.id
            WHERE {$whereClause}
            AND (
                (ui.institution_id = :institution_id AND ui.secondary_institution_id IS NULL) 
                OR 
                ui.secondary_institution_id = :institution_id
            )
            AND ui.is_active = 1
            ORDER BY 
                CASE 
                    WHEN ui.institution_id = :institution_id AND ui.secondary_institution_id IS NULL THEN 1
                    WHEN ui.secondary_institution_id = :institution_id THEN 2
                END,
                ui.created_at DESC
        ";

        $this->db->query($sql);
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        
        return $this->db->fetchAll();
    }

    /**
     * Get users by multiple institutions
     */
    public function getUsersByInstitutions(array $institutionIds): array
    {
        if (empty($institutionIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($institutionIds) - 1) . '?';
        $sql = "
            SELECT u.*, ui.is_active as institution_active,
                   ip.company_name as institution_name
            FROM users u
            JOIN user_institutions ui ON u.id = ui.user_id
            LEFT JOIN institution_profile ip ON ui.institution_id = ip.id
            WHERE ui.institution_id IN ({$placeholders})
              AND ui.is_active = 1
              AND u.is_active = 1
            ORDER BY ui.created_at DESC
        ";

        $this->db->query($sql);
        
        foreach ($institutionIds as $index => $value) {
            $this->db->bind($index + 1, $value);
        }
        
        return $this->db->fetchAll();
    }

    /**
     * Get institution statistics for a user
     */
    public function getUserInstitutionStats(int $userId): array
    {
        $this->db->query("
            SELECT 
                COUNT(*) as count,
                ip.company_name as institution_name
            FROM user_institutions ui
            LEFT JOIN institution_profile ip ON ui.institution_id = ip.id
            WHERE ui.user_id = :user_id AND ui.is_active = 1
            GROUP BY ip.company_name
        ");
        $this->db->bind(":user_id", $userId);
        
        return $this->db->fetchAll();
    }

    /**
     * Check if user belongs to institution
     */
    public function userBelongsToInstitution(int $userId, int $institutionId): bool
    {
        $this->db->query("
            SELECT id 
            FROM user_institutions 
            WHERE user_id = :user_id 
              AND (institution_id = :institution_id OR secondary_institution_id = :institution_id)
              AND is_active = 1
            LIMIT 1
        ");
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":institution_id", $institutionId);
        
        $result = $this->db->count();
        return $result > 0;
    }
}
