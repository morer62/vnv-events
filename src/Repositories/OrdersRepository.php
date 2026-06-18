<?php

namespace App\Repositories;

class OrdersRepository extends BaseRepository
{
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID_HALF = 'paid_half';
    public const PAYMENT_STATUS_PAID = 'paid_full';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_STATUS_PENDING,
        self::PAYMENT_STATUS_PAID_HALF,
        self::PAYMENT_STATUS_PAID,
    ];

    public function __construct() {
        $this->db = new Connection();
        $this->table = "orders";
    }

    // En OrdersServicesAssignedRepository.php
    
    

   public function getOrdersWithTasksByUser(int $userId): array
    {
        $sql = "
            SELECT DISTINCT o.*, osi.is_confirmed
            FROM orders o
            INNER JOIN orders_team_tasks ott ON ott.id_order = o.id
            LEFT JOIN orders_staff_invites osi ON osi.id_order = o.id AND osi.id_user = :userId
            WHERE ott.id_user = :userId
            AND is_archived = 0
            AND o.event_date >= CURDATE()
            ORDER BY o.event_date ASC, o.start_time ASC
        ";

        $this->db->query($sql);
        $this->db->bind(":userId", $userId);
        return $this->db->fetchAll();
    }

    public function getRemainingTeamSlots(int $orderId): int
    {
        $sql = "
            SELECT 
                o.total_team_needed, 
                (
                    SELECT COUNT(*) 
                    FROM orders_staff_invites 
                    WHERE id_order = o.id AND is_confirmed = 1
                ) AS confirmed_count
            FROM orders o
            WHERE o.id = :id
        ";

        $this->db->query($sql);
        $this->db->bind(":id", $orderId);
        $result = $this->db->fetchOne();

        if (!$result) {
            return 0;
        }

        $remaining = (int)$result->total_team_needed - (int)$result->confirmed_count;
        return max(0, $remaining);
    }




   public function getOrdersByInvitation(int $userId): array
    {
        $sql = "
            SELECT DISTINCT o.*, osi.is_confirmed, i.company_name as institution_name
            FROM orders o
            INNER JOIN orders_staff_invites osi ON osi.id_order = o.id
            LEFT JOIN institution_profile i ON i.id_owner = o.id_owner
            WHERE osi.id_user = :userId
            AND is_archived = 0
            AND o.event_date >= CURDATE()
            ORDER BY o.event_date ASC, o.start_time ASC
        ";

        $this->db->query(sql: $sql);
        $this->db->bind(param: ":userId", value: $userId);

        return $this->db->fetchAll();
    }




   


    public function calculateTotal(int $orderId): float
    {
        $sql = "SELECT subtotal FROM orders_services_assigned WHERE id_order = ?";
        $this->db->query($sql);
        $this->db->bind(1, $orderId);
        $rows = $this->db->fetchAll();

        $subtotal = array_sum(array_column($rows, 'subtotal'));

        // Obtener impuestos y descuentos desde la orden
        $order = $this->getOne(["id" => $orderId]);
        $discount = 0;

        if ($order->discount_type === 'percent') {
            $discount = $subtotal * ($order->discount_value / 100);
        } else {
            $discount = $order->discount_value;
        }

        $tax = $subtotal * ($order->tax_percentage / 100);

        return round($subtotal + $tax - $discount, 2);
    }


    public function getById(int $id): ?array
    {
        $items = $this->getAllBy(["id" => $id]);
        return isset($items[0]) && is_array($items[0]) ? $items[0] : null;
    }


    public function getFiltered(array $baseFilters, ?string $search, ?string $startDate, ?string $endDate): array
    {
        //$sql = "SELECT * FROM orders WHERE id_user = :id_user";
        $sql = "SELECT * FROM orders WHERE id_user = :id_user AND is_archived = 0";

        $params = [":id_user" => $baseFilters["id_user"]];

        if ($startDate) {
            $sql .= " AND event_date >= :startDate";
            $params[":startDate"] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND event_date <= :endDate";
            $params[":endDate"] = $endDate;
        }

        if ($search) {
            $sql .= " AND id_client IN (
                SELECT id FROM users 
                WHERE name LIKE :search OR lastname LIKE :search OR email LIKE :search
            )";
            $params[":search"] = "%" . $search . "%";
        }

        $sql .= " ORDER BY event_date ASC";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

 
        return $this->db->fetchAll();
    }


    public function getAllWhereClientOrAssociated(int $clientId, array $associatedOwnerIds): array
    {
        $placeholders = implode(',', array_fill(0, count($associatedOwnerIds), '?'));

        $sql = "
            SELECT * FROM orders
            WHERE is_archived = 0
            AND (
                id_client = ?
                OR id_owner IN ($placeholders)
            )
            ORDER BY event_date DESC
        ";


        $this->db->query($sql);
        $this->db->bind(1, $clientId);
        foreach ($associatedOwnerIds as $index => $id) {
            $this->db->bind($index + 2, $id); // porque el 1 ya está usado
        }

        return $this->db->fetchAll();
    }

    public function getOrdersForClient(int $clientId): array
    {
        return $this->getAllBy(["id_client" => $clientId]);
    }

   





    public function getUpcomingOrders(int $userId): array
    {
        $sql = "SELECT * FROM orders WHERE id_user = :id_user AND event_date >= CURDATE() AND is_archived = 0 ORDER BY event_date ASC";
        
        $this->db->query($sql);
        $this->db->bind(":id_user", $userId);
 
        return $this->db->fetchAll();
    }

    public function getPastOrders(int $userId): array
    {
        $sql = "SELECT * FROM orders WHERE id_user = :id_user AND event_date < CURDATE() AND is_archived = 0 ORDER BY event_date DESC";
        $this->db->query($sql);
        $this->db->bind(":id_user", $userId);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function getPastOrdersWithPagination(int $userId, ?string $search = null, ?string $startDate = null, ?string $endDate = null, int $limit = 10, int $offset = 0): array
    {
        $whereConditions = ["id_user = :user_id", "event_date < CURDATE()", "is_archived = 0"];
        $params = [":user_id" => $userId];

        if ($search) {
            $whereConditions[] = "(address LIKE :search OR id IN (SELECT id FROM orders WHERE id_client IN (SELECT id FROM users WHERE (name LIKE :search_name OR lastname LIKE :search_lastname OR email LIKE :search_email))))";
            $searchTerm = "%$search%";
            $params[":search"] = $searchTerm;
            $params[":search_name"] = $searchTerm;
            $params[":search_lastname"] = $searchTerm;
            $params[":search_email"] = $searchTerm;
        }

        if ($startDate) {
            $whereConditions[] = "event_date >= :start_date";
            $params[":start_date"] = $startDate;
        }

        if ($endDate) {
            $whereConditions[] = "event_date <= :end_date";
            $params[":end_date"] = $endDate;
        }

        $whereClause = implode(" AND ", $whereConditions);
        $sql = "SELECT * FROM orders WHERE $whereClause ORDER BY event_date DESC LIMIT :limit OFFSET :offset";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        $this->db->bind(":limit", $limit);
        $this->db->bind(":offset", $offset);

        return $this->db->fetchAll();
    }

    public function getPastOrdersCount(int $userId, ?string $search = null, ?string $startDate = null, ?string $endDate = null): int
    {
        $whereConditions = ["id_user = :user_id", "event_date < CURDATE()", "is_archived = 0"];
        $params = [":user_id" => $userId];

        if ($search) {
            $whereConditions[] = "(address LIKE :search OR id IN (SELECT id FROM orders WHERE id_client IN (SELECT id FROM users WHERE (name LIKE :search_name OR lastname LIKE :search_lastname OR email LIKE :search_email))))";
            $searchTerm = "%$search%";
            $params[":search"] = $searchTerm;
            $params[":search_name"] = $searchTerm;
            $params[":search_lastname"] = $searchTerm;
            $params[":search_email"] = $searchTerm;
        }

        if ($startDate) {
            $whereConditions[] = "event_date >= :start_date";
            $params[":start_date"] = $startDate;
        }

        if ($endDate) {
            $whereConditions[] = "event_date <= :end_date";
            $params[":end_date"] = $endDate;
        }

        $whereClause = implode(" AND ", $whereConditions);
        $sql = "SELECT COUNT(*) as total FROM orders WHERE $whereClause";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        $result = $this->db->fetchOne();
        return (int)$result->total;
    }

    public function getArchivedOrders(int $userId, ?string $search = null, ?string $startDate = null, ?string $endDate = null, int $limit = 10, int $offset = 0): array
    {
        $whereConditions = ["id_user = :user_id", "is_archived = 1"];
        $params = [":user_id" => $userId];

        if ($search) {
            $whereConditions[] = "(address LIKE :search OR id IN (SELECT id FROM orders WHERE id_client IN (SELECT id FROM users WHERE (name LIKE :search_name OR lastname LIKE :search_lastname OR email LIKE :search_email))))";
            $searchTerm = "%$search%";
            $params[":search"] = $searchTerm;
            $params[":search_name"] = $searchTerm;
            $params[":search_lastname"] = $searchTerm;
            $params[":search_email"] = $searchTerm;
        }

        if ($startDate) {
            $whereConditions[] = "event_date >= :start_date";
            $params[":start_date"] = $startDate;
        }

        if ($endDate) {
            $whereConditions[] = "event_date <= :end_date";
            $params[":end_date"] = $endDate;
        }

        $whereClause = implode(" AND ", $whereConditions);
        $sql = "SELECT * FROM orders WHERE $whereClause ORDER BY event_date DESC LIMIT :limit OFFSET :offset";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        $this->db->bind(":limit", $limit);
        $this->db->bind(":offset", $offset);

        return $this->db->fetchAll();
    }

    public function getArchivedOrdersCount(int $userId, ?string $search = null, ?string $startDate = null, ?string $endDate = null): int
    {
        $whereConditions = ["id_user = :user_id", "is_archived = 1"];
        $params = [":user_id" => $userId];

        if ($search) {
            $whereConditions[] = "(address LIKE :search OR id IN (SELECT id FROM orders WHERE id_client IN (SELECT id FROM users WHERE (name LIKE :search_name OR lastname LIKE :search_lastname OR email LIKE :search_email))))";
            $searchTerm = "%$search%";
            $params[":search"] = $searchTerm;
            $params[":search_name"] = $searchTerm;
            $params[":search_lastname"] = $searchTerm;
            $params[":search_email"] = $searchTerm;
        }

        if ($startDate) {
            $whereConditions[] = "event_date >= :start_date";
            $params[":start_date"] = $startDate;
        }

        if ($endDate) {
            $whereConditions[] = "event_date <= :end_date";
            $params[":end_date"] = $endDate;
        }

        $whereClause = implode(" AND ", $whereConditions);
        $sql = "SELECT COUNT(*) as total FROM orders WHERE $whereClause";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        $result = $this->db->fetchOne();
        return (int)$result->total;
    }


    public function getFiltered2(array $baseFilters, ?string $search, ?string $startDate, ?string $endDate): array
    {
        
        $params = [];
        $criteria = join("", array_map(function ($key) {
            return " AND $key = :$key ";
        }, array_keys($baseFilters)));

        foreach(array_keys($baseFilters) as $key) {
            $params[":$key"] = $baseFilters[$key];
        }

       // $sql = "SELECT * FROM orders WHERE 1=1 $criteria";
        $sql = "SELECT * FROM orders WHERE is_archived = 0 $criteria";

        if ($startDate) {
            $sql .= " AND event_date >= :startDate";
            $params[":startDate"] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND event_date <= :endDate";
            $params[":endDate"] = $endDate;
        }

        if ($search) {
            $sql .= " AND id_client IN (
                SELECT
                    t1.id
                FROM
                    users t1
                WHERE
                    LOWER(t1.name) LIKE :search OR LOWER(t1.lastname) LIKE :search OR LOWER(t1.email) LIKE :search
            )";
            $params[":search"] = "%" . $search . "%";
        }

        $sql .= " ORDER BY event_date ASC";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return $this->db->fetchAll();
    }


    public function getByIdWithoutOwnershipCheck(int $id): ?array
    {
        $query = "SELECT * FROM orders WHERE id = :id";
        $this->db->query($query);
        $this->db->bind(":id", $id);
        $item = $this->db->fetchOne();
        return $item ? (array)$item : null;
    }

    public function archiveOrder(int $orderId) {
        $sql = "UPDATE orders SET is_archived = 1, archived_at = NOW() WHERE id = :id";
        $this->db->query($sql);
        $this->db->bind(":id", $orderId);
        return $this->db->execute();
    }

    public function unarchiveOrder(int $orderId) {
        $sql = "UPDATE orders SET is_archived = 0 WHERE id = :id";
        $this->db->query($sql);
        $this->db->bind(":id", $orderId);
        return $this->db->execute();
    }




public function getOrdersForClientWithoutOwnerFilter(int $clientId): array
{
    $sql = "SELECT * FROM orders WHERE id_client = :id_client AND is_archived = 0 ORDER BY event_date DESC";
    $this->db->query($sql);
    $this->db->bind(":id_client", $clientId);
    return $this->db->fetchAll();
}

public function getOrdersForClientWithCompany(int $clientId): array
{
    $sql = "
        SELECT
            o.*,
            ip.company_name AS company_name,
            ip.logo_path AS company_logo_path,
            ip.email AS company_email,
            ip.phone AS company_phone,
            ip.address_line1 AS company_address_line1,
            ip.city AS company_city,
            ip.state AS company_state,
            ip.zip AS company_zip,
            ip.country AS company_country
        FROM orders o
        LEFT JOIN institution_profile ip ON ip.id_owner = o.id_owner
        WHERE o.id_client = :id_client
          AND o.is_archived = 0
        ORDER BY o.event_date DESC, o.created_at DESC
    ";
    $this->db->query($sql);
    $this->db->bind(":id_client", $clientId);
    return $this->db->fetchAll();
}

public function getAllByInstitutionOwner(int $institutionOwnerId, int $isArchived = 0): array
{
    $sql = "SELECT * FROM `{$this->table}` WHERE `id_owner` = :id_owner AND `is_archived` = :is_archived ORDER BY event_date DESC";
    $this->db->query($sql);
    $this->db->bind(":id_owner", $institutionOwnerId);
    $this->db->bind(":is_archived", $isArchived);
    
    return $this->db->fetchAll();
}

public function getOneByIdAndOwner(int $id, int $institutionOwnerId): ?object
{
    $sql = "SELECT * FROM `{$this->table}` WHERE `id` = :id AND `id_owner` = :id_owner LIMIT 1";
    $this->db->query($sql);
    $this->db->bind(":id", $id);
    $this->db->bind(":id_owner", $institutionOwnerId);
    
    $result = $this->db->fetchOne();
    return $result ?: null;
}

public function addWithExplicitOwner(array $data): int
{
    try {
        $keys = array_keys($data);
        $insert = "(";
        $values = "(";
    
        for ($i = 0; $i < count($keys); $i++) {
            $insert .= " `$keys[$i]`";
            $values .= " :$keys[$i]";
            if ($i != count($keys) - 1) {
                $insert .= ", ";
                $values .= ", ";
            }
        }
    
        $insert .= ")";
        $values .= ")";
    
        $sql = "INSERT INTO {$this->table} $insert VALUES $values";
        $this->db->query($sql);
    
        foreach ($data as $key => $val) {
            $this->db->bind(":$key", $val);
        }
    
        $this->db->execute();
        
        return $this->getLastId();
    } catch (\PDOException $e) {
        error_log("Error in addWithExplicitOwner: " . $e->getMessage());
        return 0;
    }
}

}
