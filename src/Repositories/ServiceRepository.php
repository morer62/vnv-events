<?php

namespace App\Repositories;

class ServiceRepository extends BaseRepository
{
    const PENDING = "PENDING";
    const APPROVED = "APPROVED";
    const REJECTED = "REJECTED";
    const SUSPENDED = "SUSPENDED";

    const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SUSPENDED];

    public function __construct()
    {
        $this->table = "service";
        $this->db = new Connection();
    }

    public function getAllWithPaymentStatusByUser(int $userId): array
    {
        $this->db->query("
            SELECT v.*, 
                CASE 
                    WHEN p.id IS NOT NULL AND p.status = 'APPROVED' AND p.renewal >= CURDATE()
                    THEN 'PAID'
                    ELSE 'UNPAID'
                END AS payment_status
            FROM service v
            LEFT JOIN payments_service_zip_codes p ON v.id = p.id_service
            WHERE v.user_id = :user_id
            GROUP BY v.id
            ORDER BY v.id DESC
        ");
        $this->db->bind(':user_id', $userId);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function getByNameLike(string $name, int $limit = 0): array
    {
        $limitQuery = $limit === 0 ? "" : "LIMIT {$limit}";

        $this->db->query("SELECT * FROM {$this->table} WHERE name LIKE :name AND status = 'APPROVED' $limitQuery");
        $this->db->bind(":name", "%$name%");
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function registerServicePaymentToAll(object $service, int $zipCount, float $total): void
    {
        $paymentDate = date("Y-m-d");
        $renewalDate = date("Y-m-d", strtotime("+30 days"));
        $amount = floatval($total);

        $db = new \App\Repositories\Connection();
        $db->query("INSERT INTO payments_all 
            (user_id, concept, concept_id, payment_date, renewal, total, status, reference)
            VALUES (:user_id, 'Service', :concept_id, :payment_date, :renewal, :total, 'ACTIVE', :ref)");

        $db->bind(":user_id", $service->user_id);
        $db->bind(":concept_id", $service->id);
        $db->bind(":payment_date", $paymentDate);
        $db->bind(":renewal", $renewalDate);
        $db->bind(":total", $amount);
        $db->bind(":ref", "Service payment for $zipCount  city(s)");

        $db->execute();

        // Crear comisión de afiliado si corresponde
        try {
            $affiliateService = new \App\Services\AffiliateService();
            $affiliateService->createCommission($service->user_id, 'listing_fee', $amount, "service_approval_{$service->id}", null, null);
        } catch (\Exception $e) {
            error_log("Error creating affiliate commission for service payment: " . $e->getMessage());
        }
    }

    // ✅ NEW METHOD: Search ALL services by location (no category filter)
    public function searchAllByLocation($lat, $lng, $distance): array
{
    $this->db->query("
        SELECT s.*, 
               sc.service_category AS category_name,
               sp.image AS main_image,
               (6371 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(s.lat)) * 
                    COS(RADIANS(s.lng) - RADIANS(:lng)) + 
                    SIN(RADIANS(:lat)) * SIN(RADIANS(s.lat))
                )) AS distance
        FROM service s
        LEFT JOIN service_category sc ON sc.id = s.category_id
        LEFT JOIN service_photos sp ON sp.service_id = s.id
        WHERE 
            s.status = 'APPROVED'
        GROUP BY s.id
        HAVING distance <= :distance
        ORDER BY distance ASC
    ");

    $this->db->bind(":lat", $lat);
    $this->db->bind(":lng", $lng);
    $this->db->bind(":distance", $distance);
    $this->db->execute();
    return $this->db->fetchAll();
}


   public function searchByCategoryAndLocation($category, $lat, $lng, $distance): array
{
    $this->db->query("
        SELECT s.*, 
               sc.service_category AS category_name,
               sp.image AS main_image,
               (6371 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(s.lat)) * 
                    COS(RADIANS(s.lng) - RADIANS(:lng)) + 
                    SIN(RADIANS(:lat)) * SIN(RADIANS(s.lat))
                )) AS distance
        FROM service s
        LEFT JOIN service_category sc ON sc.id = s.category_id
        LEFT JOIN service_photos sp ON sp.service_id = s.id
        WHERE 
            s.status = 'APPROVED'
            AND s.category_id = :category
        GROUP BY s.id
        HAVING distance <= :distance
        ORDER BY distance ASC
    ");

    $this->db->bind(":lat", $lat);
    $this->db->bind(":lng", $lng);
    $this->db->bind(":category", $category);
    $this->db->bind(":distance", $distance);
    $this->db->execute();
    return $this->db->fetchAll();
}


    public function searchNearbyDifferentCategories($category, $lat, $lng, $distance): array
    {
        $this->db->query("
            SELECT s.*, 
                   sc.service_category AS category_name,
                   sp.image AS main_image,
                   (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(s.lat)) * 
                        COS(RADIANS(s.lng) - RADIANS(:lng)) + 
                        SIN(RADIANS(:lat)) * SIN(RADIANS(s.lat))
                    )) AS distance
            FROM service s
            LEFT JOIN service_category sc ON sc.id = s.category_id
            LEFT JOIN service_photos sp ON sp.service_id = s.id
            WHERE 
                s.status = 'APPROVED'
                AND s.category_id != :category
            GROUP BY s.id
            HAVING distance <= :distance
            ORDER BY distance ASC
        ");

        $this->db->bind(":lat", $lat);
        $this->db->bind(":lng", $lng);
        $this->db->bind(":category", $category);
        $this->db->bind(":distance", $distance);
        return $this->db->fetchAll();
    }

    public function getAllByActiveStatus(): array
    {
        $this->db->query("SELECT id FROM {$this->table} WHERE status = 'APPROVED'");
        return $this->db->fetchAll();
    }


    public function getAllByZipCode(string $zipCode): array
    {
        $query = "
            SELECT 
                s.*, 
                sc.id AS category_id,
                sc.service_category AS category_name,
                psz.id AS payment_id,
                psz.zip_code,
                psz.payment_date,
                psz.renewal,
                psz.total,
                psz.status AS payment_status
            FROM payments_service_zip_codes AS psz
            INNER JOIN {$this->table} AS s ON s.id = psz.id_service
            LEFT JOIN service_category AS sc ON sc.id = s.category_id
            WHERE 
                psz.status = 'APPROVED'
                AND psz.renewal >= CURDATE()
                AND psz.zip_code = :zip_code
                AND s.status = 'APPROVED'
        ";
    
        $this->db->query($query);
        $this->db->bind(":zip_code", $zipCode);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function getExpiredWithUser(): array
    {
        $query = "
            SELECT DISTINCT pszc.id, pszc.id_service, pszc.payment_date, pszc.renewal, pszc.status as active,
                u.id AS user_id, u.name, u.email, u.phone, u.membership_type, u.membership_due_date, uc.token AS user_token,
                s.name AS service_name
            FROM payments_service_zip_codes pszc
            INNER JOIN service s ON s.id = pszc.id_service
            INNER JOIN users u ON u.id = s.user_id
            INNER JOIN user_cards uc ON uc.id_user = u.id AND uc.main_card = 'yes'
            WHERE s.expiration_date < CURDATE()
            AND pszc.status = 'ACTIVE'
        ";
        $this->db->query($query);
        $this->db->execute();
        return $this->db->fetchAll();
    }



public function getServiceWithDetailsById(int $id): ?object
{
    $this->db->query("
        SELECT s.*, 
               sc.service_category AS category_name
        FROM service s
        LEFT JOIN service_category sc ON sc.id = s.category_id
        WHERE s.id = :id
        LIMIT 1
    ");
    
    $this->db->bind(':id', $id);
    $this->db->execute();
    $services = $this->db->fetchAll();
    
    if (empty($services)) {
        return null;
    }

    $service = (object) $services[0];

    // Photos
    $this->db->query("SELECT image FROM service_photos WHERE service_id = :service_id ORDER BY id ASC");
    $this->db->bind(':service_id', $id);
    $this->db->execute();
    $photos = $this->db->fetchAll();
    $service->photos = array_map(fn($p) => is_object($p) ? $p->image : $p['image'], $photos);

    // Promotions
    $this->db->query("
        SELECT id, name, description, image, start_date, end_date
        FROM service_promotions 
        WHERE service_id = :service_id 
        ORDER BY start_date DESC
    ");
    $this->db->bind(':service_id', $id);
    $this->db->execute();
    $promotions = $this->db->fetchAll();
    $service->promotions = array_map(fn($p) => (object) $p, $promotions);

    // Events
    $this->db->query("
        SELECT id, name, description, external_link, start_date
        FROM service_events 
        WHERE service_id = :service_id
        ORDER BY start_date ASC
    ");
    $this->db->bind(':service_id', $id);
    $this->db->execute();
    $events = $this->db->fetchAll();
    $service->events = array_map(fn($e) => (object) $e, $events);

    return $service;
}


public function getLastApproved(int $limit = 5): array
{
    $this->db->query("
        SELECT s.*, 
               sc.service_category AS category_name,
               sp.image AS main_image
        FROM service s
        LEFT JOIN service_category sc ON sc.id = s.category_id
        LEFT JOIN service_photos sp ON sp.service_id = s.id
        WHERE s.status = 'APPROVED'
        GROUP BY s.id
        ORDER BY s.id DESC
        LIMIT :limit
    ");
    $this->db->bind(":limit", $limit);
    $this->db->execute();
    return $this->db->fetchAll();
}

}