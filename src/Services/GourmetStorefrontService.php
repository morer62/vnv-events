<?php

namespace App\Services;

use App\Repositories\Connection;

final class GourmetStorefrontService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    /** @return array<string,mixed> */
    public function build(int $ownerId, string $siteKey): array
    {
        $settings = (new GourmetExpressService($this->db))->settings($ownerId, $siteKey);
        $products = $this->products($ownerId, $siteKey);
        $categories = [
            'family-dinners' => ['title' => 'Family Occasion Meals', 'products' => []],
            'party-boxes' => ['title' => 'Party Boxes', 'products' => []],
            'weeknight-dinners' => ['title' => 'Weeknight Dinners', 'products' => []],
            'desserts' => ['title' => 'Desserts', 'products' => []],
            'holidays' => ['title' => 'Seasonal', 'products' => []],
        ];

        foreach ($products as $product) {
            $slug = $this->categorySlug((string)$product->category_slugs, (string)$product->name);
            $categories[$slug]['products'][] = $product;
        }
        if (count($categories['weeknight-dinners']['products']) < 3) {
            unset($categories['weeknight-dinners']);
        }

        $zones = $this->rows('SELECT zone_name,county_name,delivery_fee,free_delivery_threshold,zip_codes_json FROM store_gourmet_delivery_zones WHERE id_owner=:owner AND site_key=:site AND status=\'ACTIVE\' ORDER BY id', $ownerId, $siteKey);
        $windows = $this->rows('SELECT label,start_time,end_time,capacity FROM store_gourmet_windows WHERE id_owner=:owner AND site_key=:site AND status=\'ACTIVE\' ORDER BY start_time', $ownerId, $siteKey);
        $party = $categories['party-boxes']['products'] ?? [];
        $family = $categories['family-dinners']['products'] ?? [];

        $holidayDeadlines = json_decode((string)($settings['holiday_deadlines_json'] ?? '[]'), true);
        if (!is_array($holidayDeadlines)) $holidayDeadlines = [];
        $seasonalProducts = [];
        $month = (int)date('n');
        if ($month >= 10 && $month <= 12) {
            foreach ($categories['holidays']['products'] ?? [] as $product) {
                $config = $holidayDeadlines[(string)$product->slug] ?? null;
                if (!is_array($config)) continue;
                $deadline = trim((string)($config['order_deadline'] ?? ''));
                $product->holiday_config = $config;
                $product->holiday_sold_out = $deadline !== '' && strtotime($deadline . ' 23:59:59') < time();
                $seasonalProducts[] = $product;
            }
        }

        return [
            'settings' => $settings,
            'categories' => $categories,
            'zones' => $zones,
            'windows' => $windows,
            'lowest_box_price' => $this->lowestPrice($party),
            'lowest_tray_price' => $this->lowestPrice($family),
            'hero_image' => trim((string)($settings['hero_image_url'] ?? '')) ?: $this->firstImage(array_merge($party, $family)),
            'seasonal_products' => $seasonalProducts,
        ];
    }

    /** @return object[] */
    private function products(int $ownerId, string $siteKey): array
    {
        $sql = "SELECT p.*,
                GROUP_CONCAT(DISTINCT LOWER(c.slug) ORDER BY c.slug SEPARATOR ',') category_slugs,
                MIN(CASE WHEN v.id IS NULL THEN COALESCE(NULLIF(p.promo_price,0),p.price)
                         ELSE COALESCE(NULLIF(v.promo_price,0),v.price) END) min_price,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN v.status='ACTIVE' THEN v.guests_min END ORDER BY v.sort_order,v.id),',',1) smallest_guests_min,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN v.status='ACTIVE' THEN v.guests_max END ORDER BY v.sort_order,v.id),',',1) smallest_guests_max,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN v.status='ACTIVE' THEN v.piece_count END ORDER BY v.sort_order,v.id),',',1) smallest_piece_count
            FROM store_products p
            LEFT JOIN store_products_categories pc ON pc.id_product=p.id
            LEFT JOIN store_categories c ON c.id=pc.id_category
            LEFT JOIN store_product_variations v ON v.id_product=p.id AND v.status='ACTIVE'
            WHERE p.id_owner=:owner AND p.site_key=:site AND p.status='ACTIVE' AND p.is_public=1
              AND p.purchase_mode='DIRECT' AND p.fulfillment_type='DELIVERY'
              AND COALESCE(p.is_addon_only,0)=0 AND COALESCE(p.product_role,'MAIN')<>'ADDON'
            GROUP BY p.id ORDER BY p.is_featured DESC,
              CASE p.slug WHEN 'chicken-chorizo-paella' THEN 10 WHEN 'homemade-lasagna-pasticho' THEN 20 WHEN 'pasta-al-horno' THEN 30 WHEN 'seafood-paella' THEN 40 ELSE 100 END,
              p.name";
        $this->db->query($sql);
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':site', $siteKey);
        return $this->db->fetchAll() ?: [];
    }

    /** @return object[] */
    private function rows(string $sql, int $ownerId, string $siteKey): array
    {
        try {
            $this->db->query($sql);
            $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
            $this->db->bind(':site', $siteKey);
            return $this->db->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Gourmet storefront config] ' . $e->getMessage());
            return [];
        }
    }

    private function categorySlug(string $categorySlugs, string $name): string
    {
        $value = strtolower($categorySlugs . ' ' . $name);
        if (preg_match('/holiday|seasonal|thanksgiving|christmas|nochebuena|hallaca/', $value)) return 'holidays';
        if (preg_match('/dessert|sweet|cake|pie|ensaimada|natilla|buñuelo/', $value)) return 'desserts';
        if (preg_match('/party-box|appetizer|charcuterie|latin-bites|signature-appetizer/', $value)) return 'party-boxes';
        if (preg_match('/weeknight/', $value)) return 'weeknight-dinners';
        return 'family-dinners';
    }

    /** @param object[] $products */
    private function lowestPrice(array $products): ?float
    {
        $prices = array_values(array_filter(array_map(static fn(object $p): float => (float)($p->min_price ?? 0), $products), static fn(float $price): bool => $price > 0));
        return $prices ? min($prices) : null;
    }

    /** @param object[] $products */
    private function firstImage(array $products): ?string
    {
        foreach ($products as $product) {
            if (trim((string)($product->main_image ?? '')) !== '') return (string)$product->main_image;
        }
        return null;
    }
}
