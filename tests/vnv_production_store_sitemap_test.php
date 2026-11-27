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
    use App\Services\VnvStoreProduction;
    use App\Utils\SiteContext;

    require_once __DIR__ . '/../src/Services/VnvStoreProduction.php';
    require_once __DIR__ . '/../src/Services/VnvProductionCatalog.php';
    require_once __DIR__ . '/../src/Services/SeoFilesGeneratorService.php';

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
