<?php
declare(strict_types=1);

namespace App\Utils {
    final class SiteContext {
        public static string $key = 'vnvevents';
        public static function siteKey(): string { return self::$key; }
        public static function publicBaseUrl(): string { return 'https://' . self::$key . '.example'; }
    }
    final class AvomealContext {
        public static function ownerId(): int { return 7; }
    }
}

namespace App\Repositories {
    final class StoreProductsRepository {
        public function getPublicSitemapEntries(int $limit, int $owner, string $site): array {
            $rows = [
                (object)['slug' => 'existing-product', 'name' => 'Existing product'],
                (object)['slug' => 'hidden-addon', 'name' => 'Add-on', 'is_addon_only' => 1],
            ];
            if ($site === 'vnvevents') {
                $rows[] = (object)['slug' => 'production-essential/', 'name' => 'Legacy row sharing a proposal URL'];
            }
            return $rows;
        }
    }
    final class StoreCategoriesRepository {
        public function getPublicSitemapEntries(int $limit, int $owner, string $site): array {
            return [(object)['slug' => 'existing-category', 'name' => 'Existing category']];
        }
    }
}

namespace App\Services {
    final class CateringLandingProductService {
        public static function canonicalLandingSlugs(): array { return []; }
    }
}

namespace {
    use App\Services\SeoFilesGeneratorService;
    use App\Services\VnvProductionCatalog;
    use App\Services\VnvStoreProduction;
    use App\Utils\SiteContext;

    require_once __DIR__ . '/../src/Services/VnvStoreProduction.php';
    require_once __DIR__ . '/../src/Services/VnvProductionCatalog.php';
    require_once __DIR__ . '/../src/Services/SeoFilesGeneratorService.php';

    $expectedFees = [
        'production-essential' => '$1,950',
        'full-event-production' => '$3,500',
        'full-event-production-plus' => '$4,500',
        'advanced-production' => 'From $6,500',
        'brand-activation' => '$1,500 for the first event day + $500 for each additional day',
    ];
    $services = VnvProductionCatalog::services();
    if (VnvStoreProduction::PROPOSALS !== array_keys($expectedFees)) {
        throw new RuntimeException('Missing or unexpected store proposal product');
    }
    foreach ($expectedFees as $slug => $price) {
        if ($services[$slug]['price'] !== $price || $services[$slug]['request_service'] !== $slug) {
            throw new RuntimeException('Incorrect fee or request destination for ' . $slug);
        }
    }
    $planningFees = [
        'event-planner' => [1500, 2250, 2950, 3750],
        'wedding-planner' => [1950, 2750, 3450, 4250],
        'day-coordinator' => [850, 1100, 1400, 1750],
    ];
    foreach ($planningFees as $key => $fees) {
        if (array_values($services[$key]['tiers']) !== $fees || count($services[$key]['tier_inclusions']) !== 4) {
            throw new RuntimeException('Incorrect guest tiers or inclusions for ' . $key);
        }
    }
    if (VnvStoreProduction::packagePrices('vnvevents') !== ['event-planner-packages' => 1500, 'wedding-planner-packages' => 1950, 'day-coordinator-service-packages-premium' => 850]
        || VnvStoreProduction::packagePrices('miamitechlab') !== []) {
        throw new RuntimeException('Unexpected planning product slugs, prices or site scope');
    }

    $directory = sys_get_temp_dir() . '/vnv-sitemap-' . bin2hex(random_bytes(8));
    mkdir($directory);
    // Other sitemap groups have no fixture database in this isolated test.
    $log = $directory . '/test.log';
    ini_set('error_log', $log);
    $file = $directory . '/sitemap-store.xml';
    try {
        foreach (['vnvevents', 'miamitechlab'] as $site) {
            SiteContext::$key = $site;
            $generator = new SeoFilesGeneratorService($directory);
            $result = $generator->generate('sitemap_store');
            if ($result['status'] !== 'success') {
                throw new RuntimeException('Store sitemap generation failed for ' . $site);
            }
            $xml = new DOMDocument();
            if (!$xml->load($file)) throw new RuntimeException('Invalid Store sitemap XML');
            $paths = [];
            foreach ($xml->getElementsByTagName('loc') as $location) {
                $paths[] = $location->textContent;
            }
            $base = SiteContext::publicBaseUrl();
            $expected = [$base . '/product/existing-product', $base . '/product-category/existing-category/'];
            if ($site === 'vnvevents') {
                foreach (VnvStoreProduction::PROPOSALS as $slug) {
                    $expected[] = $base . '/product/' . $slug;
                }
            }
            sort($expected);
            sort($paths);
            if ($paths !== $expected) {
                throw new RuntimeException('Unexpected, duplicated or missing URLs for ' . $site . ': ' . json_encode($paths));
            }
        }
        echo "Passed Store sitemap checks for VNV proposal services, URL collisions, catalog products, add-ons and site isolation.\n";
    } finally {
        if (is_file($file)) unlink($file);
        if (is_file($log)) unlink($log);
        rmdir($directory);
    }
}
