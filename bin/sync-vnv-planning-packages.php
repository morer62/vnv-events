<?php
declare(strict_types=1);

use App\Services\VnvProductionCatalog as Catalog;
use App\Services\VnvStoreProduction;
use App\Utils\SiteContext;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$mode = $argv[1] ?? '--check';
if (PHP_SAPI !== 'cli' || !in_array($mode, ['--check', '--apply', '--verify'], true) || SiteContext::siteKey() !== 'vnvevents') {
    throw new RuntimeException('Use the VNV Events CLI with --check, --apply or --verify.');
}

$pdo = new PDO($_ENV['DATABASE_URL'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4");
$owner = SiteContext::businessOwnerId();
$apply = $mode === '--apply';
$lock = $apply ? ' FOR UPDATE' : '';
$select = static function (string $sql, array $params) use ($pdo): array {
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
};
$deltas = 0;
$change = static function (string $sql, array $params) use ($apply, &$deltas, $pdo): void {
    ++$deltas;
    if ($apply) $pdo->prepare($sql)->execute($params);
};
$products = Catalog::services();
$tierSlugs = ['guests-up-to-75', 'guests-76-150', 'guests-151-250', 'guests-251-plus'];
$oldVariants = [
    'event-planner' => ['EPP1-1','EPP1-2','EPP1-3','EPP1-4'],
    'day-coordinator' => ['DC1-1','DC1-2','DC1-3','DC1-4'],
    'wedding-planner' => [],
];
try {
    $pdo->beginTransaction();
    $category = $select("SELECT id FROM store_categories WHERE id_owner=? AND site_key='vnvevents' AND slug=? AND status='ACTIVE'".$lock, [$owner, VnvStoreProduction::CATEGORY]);
    if (count($category) !== 1) throw new RuntimeException('Expected one active VNV planning category.');
    $categoryId = (int)$category[0]['id'];
    foreach (Catalog::PACKAGE_SLUGS as $key => $slug) {
        $service = $products[$key];
        $rows = $select("SELECT * FROM store_products WHERE id_owner=? AND site_key='vnvevents' AND slug IN (?,?)".$lock,
            [$owner, $slug, $key === 'event-planner' ? VnvStoreProduction::LEGACY_PLANNER_SLUG : $slug]);
        if (count($rows) > 1 || (!$rows && $key !== 'wedding-planner')) throw new RuntimeException("Missing or ambiguous product: $key.");
        $row = $rows[0] ?? null;
        $name = $service['name'];
        $starting = reset($service['tiers']);
        $html = '<p>'.$service['details'].'</p><ul>';
        foreach ($service['tiers'] as $tier => $price) {
            $index = array_search($tier, Catalog::GUEST_TIERS, true);
            $html .= '<li><strong>'.$tier.' — $'.number_format($price).'</strong> · '.$service['tier_inclusions'][$index].'</li>';
        }
        $html .= '</ul><p>'.$service['extras'].'</p>';
        if ($key !== 'day-coordinator') {
            $html .= '<p>On-site coordination is booked separately as <a href="/product/'.Catalog::PACKAGE_SLUGS['day-coordinator'].'">Day Coordinator</a>.</p>';
        }

        if (!$row) {
            $change("INSERT INTO store_products (id_owner,site_key,name,slug,sku,short_description,description,product_type,price,main_image,is_public,status,purchase_mode,allow_immediate_payment,allow_recurring_purchase,fulfillment_type,created_at,updated_at)
                VALUES (?,'vnvevents',?,?,?, ?,?,'VARIABLE',?,?,1,'ACTIVE','REQUEST',0,0,'SERVICE',NOW(),NOW())",
                [$owner,$name,$slug,'VNV-WEDDING-PLANNER',$service['summary'],$html,$starting,'/assets/images/planner1.jpg']);
            $id = $apply ? (int)$pdo->lastInsertId() : 0;
        } else {
            $id = (int)$row['id'];
            if ($row['purchase_mode'] !== 'REQUEST' || $row['product_type'] !== 'VARIABLE' || $row['fulfillment_type'] !== 'SERVICE'
                || !$row['is_public'] || $row['status'] !== 'ACTIVE' || ($key === 'wedding-planner' && $row['sku'] !== 'VNV-WEDDING-PLANNER')) {
                throw new RuntimeException("Unexpected store configuration for $key. Review before applying.");
            }
            if ($row['slug'] !== $slug || $row['name'] !== $name || $row['description'] !== $html || $row['short_description'] !== $service['summary']
                || (float)$row['price'] !== (float)$starting || $row['promo_price'] !== null) {
                $change("UPDATE store_products SET name=?,slug=?,short_description=?,description=?,price=?,promo_price=NULL,updated_at=NOW() WHERE id=? AND id_owner=? AND site_key='vnvevents'",
                    [$name,$slug,$service['summary'],$html,$starting,$id,$owner]);
            }
        }
        if (!$select("SELECT id FROM store_products_categories WHERE id_product=? AND id_category=?".$lock, [$id,$categoryId])) {
            $change('INSERT INTO store_products_categories (id_owner,id_product,id_category) VALUES (?,?,?)', [$owner,$id,$categoryId]);
        }
        $visibility = $select("SELECT id,is_visible,visibility_status FROM site_visibility WHERE site_key='vnvevents' AND entity_type='store_product' AND entity_id=?".$lock, [$id]);
        if (!$visibility) {
            $change("INSERT INTO site_visibility (site_key,entity_type,entity_id,id_user_business,is_visible,visibility_status,created_at,updated_at) VALUES ('vnvevents','store_product',?,?,1,'VISIBLE',NOW(),NOW())", [$id,$owner]);
        } elseif (count($visibility) !== 1 || !$visibility[0]['is_visible'] || $visibility[0]['visibility_status'] !== 'VISIBLE') {
            throw new RuntimeException("Unexpected visibility for $key.");
        }

        $variations = $select("SELECT * FROM store_product_variations WHERE id_product=?".$lock, [$id]);
        $bySlug = [];
        foreach ($variations as $v) {
            if (in_array($v['slug'], $tierSlugs, true)) {
                if (isset($bySlug[$v['slug']])) throw new RuntimeException("Duplicate guest tier for $key.");
                $bySlug[$v['slug']] = $v;
            } elseif ($v['status'] === 'ACTIVE') {
                if (!in_array($v['sku'], $oldVariants[$key], true)) throw new RuntimeException("Unrecognized active variation on $key. Review before applying.");
                $change("UPDATE store_product_variations SET status='INACTIVE',updated_at=NOW() WHERE id=? AND id_product=?", [$v['id'],$id]);
            }
        }
        foreach ($tierSlugs as $i => $tierSlug) {
            $label = Catalog::GUEST_TIERS[$i];
            $sku = strtoupper('VNV-'.$key.'-'.$tierSlug);
            $price = $service['tiers'][$label];
            $low = $i === 0 ? 1 : Catalog::GUEST_CAPS[$i - 1] + 1;
            $high = Catalog::GUEST_CAPS[$i];
            $v = $bySlug[$tierSlug] ?? null;
            if (!$v) {
                $change("INSERT INTO store_product_variations (id_owner,id_product,name,slug,sku,price,guests_min,guests_max,sort_order,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'ACTIVE',NOW(),NOW())",
                    [$owner,$id,$label,$tierSlug,$sku,$price,$low,$high,$i]);
            } else {
                if ($v['sku'] !== $sku) throw new RuntimeException("Guest tier slug is already used for another variation in $key.");
                if ($v['name'] !== $label || (float)$v['price'] !== (float)$price || $v['promo_price'] !== null || (int)$v['sort_order'] !== $i
                    || (int)$v['guests_min'] !== $low || ($v['guests_max'] === null ? null : (int)$v['guests_max']) !== $high || $v['status'] !== 'ACTIVE') {
                    $change("UPDATE store_product_variations SET name=?,price=?,promo_price=NULL,guests_min=?,guests_max=?,sort_order=?,status='ACTIVE',updated_at=NOW() WHERE id=? AND id_product=?",
                        [$label,$price,$low,$high,$i,$v['id'],$id]);
                }
            }
        }
        echo $name.' '.($id ? '#'.$id : '(new)').' /product/'.$slug.' — '.implode(', ',array_map(static fn($fee) => '$'.number_format($fee),$service['tiers'])).PHP_EOL;
    }
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo ($apply ? 'Applied' : 'Pending').' operations: '.$deltas.PHP_EOL;
    exit($mode === '--verify' && $deltas > 0 ? 1 : 0);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
