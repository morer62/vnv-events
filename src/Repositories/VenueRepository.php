<?php

namespace App\Repositories;

class VenueRepository extends BaseRepository
{
    const PENDING = "PENDING";
    const APPROVED = "APPROVED";
    const REJECTED = "REJECTED";
    const SUSPENDED = "SUSPENDED";

    const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SUSPENDED];


    public function __construct()
    {
        $this->table = "venues";
        $this->db = new Connection();
    }

    public function getAllByActiveStatus(): array
    {
        $this->db->query("SELECT id FROM {$this->table} WHERE status = 'APPROVED'");
        return $this->db->fetchAll();
    }

    public function getAllWithPaymentStatusByUser(int $userId): array
    {
        $this->db->query("
            SELECT v.*, 
                CASE 
                    WHEN p.id IS NOT NULL AND p.active = 'yes' AND p.renewal >= CURDATE() 
                    THEN 'PAID'
                    ELSE 'UNPAID'
                END AS payment_status
            FROM venues v
            LEFT JOIN payments_venues p ON v.id = p.id_venues
            WHERE v.user_id = :user_id
            ORDER BY v.id DESC
        ");
        $this->db->bind(':user_id', $userId);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function getByNameLike(string $name, $limit = 0): array
    {
        $limitQuery = $limit === 0 ? "" : "LIMIT {$limit}";

        $this->db->query("SELECT * FROM venues WHERE name LIKE :name AND status = 'APPROVED' $limitQuery");
        $this->db->bind(":name", "%$name%");
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function insertFull(array $data): int
    {
        $fields = array_keys($data);
        $placeholders = array_map(fn($key) => ':' . $key, $fields);

        $sql = "INSERT INTO `$this->table` (" . implode(",", $fields) . ")
                VALUES (" . implode(",", $placeholders) . ")";

        $this->db->query($sql);
        foreach ($data as $key => $value) {
            $this->db->bind(":$key", $value);
        }

        $this->db->execute();
        return $this->db->lastId();
    }


    public function getLastApproved(int $limit = 5): array
    {
        $this->db->query("
            SELECT v.*, vc.name AS category_name, vp.image AS main_image
            FROM venues v
            LEFT JOIN venue_categories vc ON vc.id = v.category_id
            LEFT JOIN venue_photos vp ON vp.venue_id = v.id
            INNER JOIN payments_venues p ON p.id_venues = v.id
            WHERE v.status = 'APPROVED'
            AND p.active = 'yes'
            AND p.renewal >= CURDATE()
            GROUP BY v.id
            ORDER BY v.id DESC
            LIMIT :limit
        ");
        $this->db->bind(':limit', $limit);
        return $this->db->fetchAll();
    }


    public function registerVenuePaymentToAll(object $venue, float $amount): void
    {
        $paymentDate = date("Y-m-d");
        //  $renewalDate = date("Y-m-d", strtotime("+1 month"));
    $renewalDate = date("Y-m-d", strtotime("+".$_ENV["FREE_DAYS_BEFORE_RENEWAL_PLANNER_HUB"]." days"));


        $db = new \App\Repositories\Connection();
        $db->query("INSERT INTO payments_all 
        (user_id, concept, concept_id, payment_date, renewal, total, status, reference)
        VALUES (:user_id, 'Venue', :concept_id, :payment_date, :renewal, :total, 'ACTIVE', :ref)");

        $db->bind(":user_id", $venue->user_id);
        $db->bind(":concept_id", $venue->id);
        $db->bind(":payment_date", $paymentDate);
        $db->bind(":renewal", $renewalDate);
        $db->bind(":total", $amount);
        $db->bind(":ref", "Venue approval and activation");

        $db->execute();

        // Crear comisión de afiliado si corresponde
        try {
            $affiliateService = new \App\Services\AffiliateService();
            $affiliateService->createCommission($venue->user_id, 'listing_fee', $amount, "venue_approval_{$venue->id}", null, null);
        } catch (\Exception $e) {
            error_log("Error creating affiliate commission for venue payment: " . $e->getMessage());
        }
    }

    public function getAllByRoundDistance($lat, $lon, $distance, $limit = 0): array {
        $limitQuery = $limit === 0 ? "" : "LIMIT {$limit}";

        $this->db->query("SELECT
            *,
            (6371 * ACOS(
                COS(RADIANS(:lat)) * COS(RADIANS(lat)) *
                COS(RADIANS(lng) - RADIANS(:long)) +
                SIN(RADIANS(:lat)) * SIN(RADIANS(lat))
            )) AS distance
        FROM venues
        WHERE status = 'APPROVED'
        HAVING distance <= :distance
        $limitQuery
        ");

        $this->db->bind(":lat", $lat);
        $this->db->bind(":long", $lon);
        $this->db->bind(":lat", $lat);
        $this->db->bind(":distance", $distance);
        return $this->db->fetchAll();
    }

    public function searchByCategoryAndLocation($category, $lat, $lng, $distance): array
    {
        $whereCategory = $category ? "AND v.category_id = :category" : "";

        $this->db->query("
            SELECT v.*, 
                vc.name AS category_name,
                vp.image AS main_image,
                (6371 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(v.lat)) *
                    COS(RADIANS(v.lng) - RADIANS(:lng)) +
                    SIN(RADIANS(:lat)) * SIN(RADIANS(v.lat))
                )) AS distance
            FROM venues v
            LEFT JOIN venue_categories vc ON vc.id = v.category_id
            INNER JOIN payments_venues p ON p.id_venues = v.id
            LEFT JOIN venue_photos vp ON vp.venue_id = v.id
            WHERE v.status = 'APPROVED'
              AND p.active = 'yes'
              AND p.renewal >= CURDATE()
              $whereCategory
            GROUP BY v.id
            HAVING distance <= :distance
            ORDER BY distance ASC
        ");
        $this->db->bind(':lat', $lat);
        $this->db->bind(':lng', $lng);
        if ($category) {
            $this->db->bind(':category', $category);
        }
        $this->db->bind(':distance', $distance);
        return $this->db->fetchAll();
    }

    public function getExpiredWithUser(): array
    {
        $query = "
            SELECT pv.id, pv.id_venues, pv.payment_date, pv.renewal, pv.active,
                u.id AS user_id, u.name, u.email, u.phone, u.membership_type, u.membership_due_date, uc.token AS user_token,
                v.name AS venue_name
            FROM payments_venues pv
            INNER JOIN venues v ON v.id = pv.id_venues
            INNER JOIN users u ON u.id = v.user_id
            INNER JOIN user_cards uc ON uc.id_user = u.id AND uc.main_card = 'yes'
            WHERE v.expiration_date < CURDATE()
            AND pv.active = 'yes'
            GROUP BY pv.id_venues, u.id
        ";

        $this->db->query($query);
        $this->db->execute();
        return $this->db->fetchAll();
    }



    public function searchNearbyDifferentCategories($category, $lat, $lng, $distance): array
    {
        $whereCategory = $category ? "AND v.category_id != :category" : "";

        $this->db->query("
            SELECT v.*, 
                vc.name AS category_name,
                vp.image AS main_image,
                (6371 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(v.lat)) *
                    COS(RADIANS(v.lng) - RADIANS(:lng)) +
                    SIN(RADIANS(:lat)) * SIN(RADIANS(v.lat))
                )) AS distance
            FROM venues v
            LEFT JOIN venue_categories vc ON vc.id = v.category_id
            INNER JOIN payments_venues p ON p.id_venues = v.id
            LEFT JOIN venue_photos vp ON vp.venue_id = v.id
            WHERE v.status = 'APPROVED'
              AND p.active = 'yes'
              AND p.renewal >= CURDATE()
              $whereCategory
            GROUP BY v.id
            HAVING distance <= :distance
            ORDER BY distance ASC
        ");
        $this->db->bind(':lat', $lat);
        $this->db->bind(':lng', $lng);
        if ($category) {
            $this->db->bind(':category', $category);
        }
        $this->db->bind(':distance', $distance);
        return $this->db->fetchAll();
    }

    public function searchByCategoriesAndLocation(array $categories, $lat, $lng, $distance): array
    {
        $hasCategories = count($categories) > 0;
        if ($hasCategories) {
            $placeholders = implode(',', array_map(function ($i) { return ":c$i"; }, array_keys($categories)));
            $whereCategory = "AND ( v.category_id IN ($placeholders)
                                   OR EXISTS (SELECT 1 FROM venue_categories_assigned vca WHERE vca.venue_id = v.id AND vca.category_id IN ($placeholders))
                                 )";
        } else {
            $whereCategory = "";
        }

        $sql = "
            SELECT v.*, 
                   vc.name AS category_name,
                   vp.image AS main_image,
                   (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(v.lat)) *
                        COS(RADIANS(v.lng) - RADIANS(:lng)) +
                        SIN(RADIANS(:lat)) * SIN(RADIANS(v.lat))
                    )) AS distance
            FROM venues v
            LEFT JOIN venue_categories vc ON vc.id = v.category_id
            INNER JOIN payments_venues p ON p.id_venues = v.id
            LEFT JOIN venue_photos vp ON vp.venue_id = v.id
            WHERE v.status = 'APPROVED'
              AND p.active = 'yes'
              AND p.renewal >= CURDATE()
              $whereCategory
            GROUP BY v.id
            HAVING distance <= :distance
            ORDER BY distance ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':lat', $lat);
        $this->db->bind(':lng', $lng);
        if ($hasCategories) {
            foreach ($categories as $idx => $cid) {
                $this->db->bind(":c$idx", $cid);
            }
        }
        $this->db->bind(':distance', $distance);
        return $this->db->fetchAll();
    }

    public function searchNearbyDifferentCategoriesMulti(array $categories, $lat, $lng, $distance): array
    {
        $hasCategories = count($categories) > 0;
        if ($hasCategories) {
            $placeholders = implode(',', array_map(function ($i) { return ":c$i"; }, array_keys($categories)));
            $whereCategory = "AND ( v.category_id NOT IN ($placeholders)
                                   AND NOT EXISTS (SELECT 1 FROM venue_categories_assigned vca WHERE vca.venue_id = v.id AND vca.category_id IN ($placeholders))
                                 )";
        } else {
            $whereCategory = "";
        }

        $sql = "
            SELECT v.*, 
                   vc.name AS category_name,
                   vp.image AS main_image,
                   (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(v.lat)) *
                        COS(RADIANS(v.lng) - RADIANS(:lng)) +
                        SIN(RADIANS(:lat)) * SIN(RADIANS(v.lat))
                    )) AS distance
            FROM venues v
            LEFT JOIN venue_categories vc ON vc.id = v.category_id
            INNER JOIN payments_venues p ON p.id_venues = v.id
            LEFT JOIN venue_photos vp ON vp.venue_id = v.id
            WHERE v.status = 'APPROVED'
              AND p.active = 'yes'
              AND p.renewal >= CURDATE()
              $whereCategory
            GROUP BY v.id
            HAVING distance <= :distance
            ORDER BY distance ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':lat', $lat);
        $this->db->bind(':lng', $lng);
        if ($hasCategories) {
            foreach ($categories as $idx => $cid) {
                $this->db->bind(":c$idx", $cid);
            }
        }
        $this->db->bind(':distance', $distance);
        return $this->db->fetchAll();
    }
}