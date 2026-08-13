<?php

namespace App\Services;

use App\Repositories\CmsContentsRepository;
use App\Repositories\Connection;
use App\Repositories\ForumTopicRepository;
use App\Repositories\LocationPagesRepository;
use App\Repositories\SeoFilesLogRepository;
use App\Repositories\StoreCategoriesRepository;
use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use DOMDocument;

class SeoFilesGeneratorService
{
    private string $publicPath;
    private ?SeoFilesLogRepository $logRepository = null;
    private ?array $publicUrlsCache = null;

    public function __construct(?string $publicPath = null)
    {
        $this->publicPath = $publicPath ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public';

        try {
            $this->logRepository = new SeoFilesLogRepository();
        } catch (\Throwable $e) {
            error_log('SEO files log repository unavailable: ' . $e->getMessage());
        }
    }

    public function generate(string $target, ?int $userId = null): array
    {
        $target = strtolower($target);

        if ($target === 'all') {
            $results = [];
            foreach (['sitemap', 'robots', 'llms', 'llms_full'] as $fileType) {
                $results[$fileType] = $this->generate($fileType, $userId);
            }

            return [
                'file_type' => 'all',
                'status' => $this->aggregateStatus($results),
                'message' => 'SEO files regenerated.',
                'items_count' => array_sum(array_map(fn ($item) => (int)($item['items_count'] ?? 0), $results)),
                'results' => $results,
            ];
        }

        return match ($target) {
            'sitemap' => $this->generateSitemap($userId),
            'sitemap_pages' => $this->generateSitemapGroup('sitemap_pages', 'sitemap-pages.xml', 'pages', $userId),
            'sitemap_blog' => $this->generateSitemapGroup('sitemap_blog', 'sitemap-blog.xml', 'blog', $userId),
            'sitemap_store' => $this->generateSitemapGroup('sitemap_store', 'sitemap-store.xml', 'store', $userId),
            'sitemap_locations' => $this->generateSitemapGroup('sitemap_locations', 'sitemap-locations.xml', 'locations', $userId),
            'robots' => $this->generateRobots($userId),
            'llms' => $this->generateLlms($userId, false),
            'llms_full' => $this->generateLlms($userId, true),
            default => [
                'file_type' => $target,
                'status' => 'failed',
                'message' => 'Unknown SEO file target.',
                'items_count' => 0,
            ],
        };
    }

    public function getFileCards(): array
    {
        $files = [
            'sitemap' => ['label' => 'sitemap.xml (index)', 'filename' => 'sitemap.xml', 'editable' => false],
            'sitemap_pages' => ['label' => 'sitemap-pages.xml', 'filename' => 'sitemap-pages.xml', 'editable' => false],
            'sitemap_blog' => ['label' => 'sitemap-blog.xml', 'filename' => 'sitemap-blog.xml', 'editable' => false],
            'sitemap_store' => ['label' => 'sitemap-store.xml', 'filename' => 'sitemap-store.xml', 'editable' => false],
            'sitemap_locations' => ['label' => 'sitemap-locations.xml', 'filename' => 'sitemap-locations.xml', 'editable' => false],
            'robots' => ['label' => 'robots.txt', 'filename' => 'robots.txt', 'editable' => true],
            'llms' => ['label' => 'llms.txt', 'filename' => 'llms.txt', 'editable' => true],
            'llms_full' => ['label' => 'llms-full.txt', 'filename' => 'llms-full.txt', 'editable' => true],
        ];

        $cards = [];
        foreach ($files as $fileType => $meta) {
            $path = $this->path($meta['filename']);
            $log = $this->logRepository?->getLatestByType($fileType);

            $cards[] = [
                'file_type' => $fileType,
                'label' => $meta['label'],
                'public_url' => $this->absoluteUrl('/' . $meta['filename']),
                'file_path' => $path,
                'exists' => is_file($path),
                'last_generated' => $log->created_at ?? (is_file($path) ? date('Y-m-d H:i:s', filemtime($path)) : null),
                'items_count' => $log->items_count ?? null,
                'status' => $log->status ?? null,
                'message' => $log->message ?? null,
                'editable' => (bool)$meta['editable'],
                'content' => is_file($path) && (bool)$meta['editable'] ? (file_get_contents($path) ?: '') : '',
            ];
        }

        return $cards;
    }

    public function saveEditableFile(string $fileType, string $content, ?int $userId = null): array
    {
        $files = [
            'robots' => 'robots.txt',
            'llms' => 'llms.txt',
            'llms_full' => 'llms-full.txt',
        ];

        if (!isset($files[$fileType])) {
            return [
                'file_type' => $fileType,
                'status' => 'failed',
                'message' => 'This SEO file is not editable from the dashboard.',
                'items_count' => 0,
            ];
        }

        $content = rtrim(str_replace(["\r\n", "\r"], "\n", $content)) . "\n";
        return $this->writeAndLog($fileType, $files[$fileType], $content, max(1, substr_count($content, "\n")), $userId);
    }

    public function buildAudit(): array
    {
        $urls = $this->collectPublicUrls();
        $latest = $this->logRepository?->getLatest();
        $groups = $this->groupSitemapUrls($urls);

        return [
            'total_detected' => count($urls),
            'pages_detected' => count($groups['pages']),
            'blog_detected' => count($groups['blog']),
            'store_detected' => count($groups['store']),
            'locations_detected' => count($groups['locations']),
            'last_generation' => $latest->created_at ?? null,
        ];
    }

    public function getPublicUrlEntries(): array
    {
        return $this->collectPublicUrls();
    }

    private function generateSitemap(?int $userId): array
    {
        $urls = $this->collectPublicUrls();
        $groups = $this->groupSitemapUrls($urls);

        $children = [
            'sitemap_pages' => [
                'filename' => 'sitemap-pages.xml',
                'path' => '/sitemap-pages.xml',
                'urls' => $groups['pages'],
            ],
            'sitemap_blog' => [
                'filename' => 'sitemap-blog.xml',
                'path' => '/sitemap-blog.xml',
                'urls' => $groups['blog'],
            ],
            'sitemap_store' => [
                'filename' => 'sitemap-store.xml',
                'path' => '/sitemap-store.xml',
                'urls' => $groups['store'],
            ],
            'sitemap_locations' => [
                'filename' => 'sitemap-locations.xml',
                'path' => '/sitemap-locations.xml',
                'urls' => $groups['locations'],
            ],
        ];

        $results = [];
        foreach ($children as $fileType => $child) {
            $results[$fileType] = $this->generateSitemapUrlset(
                $fileType,
                $child['filename'],
                $child['urls'],
                $userId
            );
        }

        $indexResult = $this->generateSitemapIndex($children, $urls, $userId);

        $status = $this->aggregateStatus([...$results, 'sitemap' => $indexResult]);
        if ($status !== 'success') {
            $indexResult['status'] = $status;
            $indexResult['message'] = 'Sitemap index generated with one or more child sitemap issues.';
        }

        return $indexResult;
    }

    private function generateSitemapGroup(string $fileType, string $filename, string $groupKey, ?int $userId): array
    {
        $groups = $this->groupSitemapUrls($this->collectPublicUrls());
        return $this->generateSitemapUrlset($fileType, $filename, $groups[$groupKey] ?? [], $userId);
    }

    private function generateSitemapUrlset(string $fileType, string $filename, array $urls, ?int $userId): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        foreach ($urls as $entry) {
            $node = $dom->createElement('url');
            $node->appendChild($dom->createElement('loc', $entry['loc']));
            $node->appendChild($dom->createElement('lastmod', $entry['lastmod']));
            $node->appendChild($dom->createElement('changefreq', $entry['changefreq']));
            $node->appendChild($dom->createElement('priority', number_format((float)$entry['priority'], 1)));
            $urlset->appendChild($node);
        }

        return $this->writeAndLog($fileType, $filename, $dom->saveXML() ?: '', count($urls), $userId);
    }

    private function generateSitemapIndex(array $children, array $allUrls, ?int $userId): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $index = $dom->createElement('sitemapindex');
        $index->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($index);

        $today = $this->today();
        foreach ($children as $child) {
            $node = $dom->createElement('sitemap');
            $node->appendChild($dom->createElement('loc', $this->absoluteUrl($child['path'])));
            $node->appendChild($dom->createElement('lastmod', $today));
            $index->appendChild($node);
        }

        return $this->writeAndLog('sitemap', 'sitemap.xml', $dom->saveXML() ?: '', count($allUrls), $userId);
    }

    private function generateRobots(?int $userId): array
    {
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /panel/',
            'Disallow: /api/',
            'Disallow: /login',
            'Disallow: /signup',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /order-access',
            'Disallow: /storage/private/',
            '',
            '# Public discovery files',
            'Host: ' . SiteContext::publicBaseUrl(),
            'Sitemap: ' . $this->absoluteUrl('/sitemap.xml'),
            'LLMs: ' . $this->absoluteUrl('/llms.txt'),
            'LLMs-Full: ' . $this->absoluteUrl('/llms-full.txt'),
            '',
        ]);

        return $this->writeAndLog('robots', 'robots.txt', $content, 1, $userId);
    }

    private function generateLlms(?int $userId, bool $full): array
    {
        $urls = $this->collectPublicUrls();
        $locations = array_values(array_filter($urls, fn ($url) => ($url['type'] ?? '') === 'location'));
        $posts = array_values(array_filter($urls, fn ($url) => ($url['type'] ?? '') === 'blog'));
        $pages = array_values(array_filter($urls, fn ($url) => in_array(($url['type'] ?? ''), ['static', 'page'], true)));
        $products = array_values(array_filter($urls, fn ($url) => ($url['type'] ?? '') === 'product'));
        $productCategories = array_values(array_filter($urls, fn ($url) => ($url['type'] ?? '') === 'product_category'));
        $forums = array_values(array_filter($urls, fn ($url) => ($url['type'] ?? '') === 'forum'));

        $lines = [
            '# VNV Events',
            '',
            'VNV Events is a South Florida event planning, production and event services business.',
            '',
            '## Main Event Services',
            '',
            '- Event planning and coordination',
            '- Corporate event production',
            '- Weddings and social celebrations',
            '- Decor, flowers and styling',
            '- DJ, sound and multimedia',
            '- Event rentals and staffing',
            '- Photo booth, video and photography support',
            '',
            '## Main Public Pages',
            '',
            '- Home: ' . $this->absoluteUrl('/'),
            '- Event Planners: ' . $this->absoluteUrl('/event-planners/'),
            '- Corporate Events: ' . $this->absoluteUrl('/corporate-events/'),
            '- Event Production: ' . $this->absoluteUrl('/event-production/'),
            '- Locations: ' . $this->absoluteUrl('/locations/'),
            '- Blog: ' . $this->absoluteUrl('/blog/'),
            '- FAQ: ' . $this->absoluteUrl('/faq/'),
            '- VNV Gourmet: ' . $this->absoluteUrl('/vnv-gourmet/'),
            '- Live Catering Stations: ' . $this->absoluteUrl('/catering-stations-south-florida/'),
            '- Crepe Station Catering: ' . $this->absoluteUrl('/crepes-catering-south-florida/'),
            '- Pasta Station Catering: ' . $this->absoluteUrl('/pasta-station-catering-south-florida/'),
            '- Pizza Station Catering: ' . $this->absoluteUrl('/pizza-station-catering-south-florida/'),
            '- Paella Catering: ' . $this->absoluteUrl('/paella-catering-south-florida/'),
            '- Taco Station Catering: ' . $this->absoluteUrl('/taco-station-catering-south-florida/'),
            '- Corporate Catering: ' . $this->absoluteUrl('/corporate-catering-south-florida/'),
            '- Store Categories: ' . $this->absoluteUrl('/store-categories/'),
            '',
            '## Main Service Areas',
            '',
        ];

        $serviceAreas = array_slice($locations, 0, $full ? 80 : 15);
        if (empty($serviceAreas)) {
            foreach (['South Florida', 'Miami-Dade', 'Broward', 'Palm Beach', 'Miami', 'Doral', 'Fort Lauderdale', 'West Palm Beach'] as $area) {
                $lines[] = '- ' . $area;
            }
        } else {
            foreach ($serviceAreas as $location) {
                $lines[] = '- ' . $location['title'] . ': ' . $location['loc'];
            }
        }

        if ($full) {
            $this->appendSection($lines, 'Public Pages', $pages, 80);
            $this->appendSection($lines, 'Store Product Categories', $productCategories, 80);
            $this->appendSection($lines, 'Public Products and Services', $products, 120);
            $this->appendSection($lines, 'Blog and Guides', $posts, 80);
            $this->appendSection($lines, 'Public Forums', $forums, 60);
            $lines[] = '## Public SEO Files';
            $lines[] = '';
            $lines[] = '- Sitemap: ' . $this->absoluteUrl('/sitemap.xml');
            $lines[] = '- Pages Sitemap: ' . $this->absoluteUrl('/sitemap-pages.xml');
            $lines[] = '- Blog Sitemap: ' . $this->absoluteUrl('/sitemap-blog.xml');
            $lines[] = '- Store Sitemap: ' . $this->absoluteUrl('/sitemap-store.xml');
            $lines[] = '- Locations Sitemap: ' . $this->absoluteUrl('/sitemap-locations.xml');
            $lines[] = '- Robots: ' . $this->absoluteUrl('/robots.txt');
            $lines[] = '- LLMs: ' . $this->absoluteUrl('/llms.txt');
            $lines[] = '';
            $lines[] = 'Do not use private panel, order, customer, or administrative data as public context.';
        } else {
            $this->appendSection($lines, 'Featured Public Content', array_slice(array_merge($products, $productCategories, $posts, $locations), 0, 12), 12);
        }

        $filename = $full ? 'llms-full.txt' : 'llms.txt';
        $fileType = $full ? 'llms_full' : 'llms';

        return $this->writeAndLog($fileType, $filename, implode("\n", $lines) . "\n", count($urls), $userId);
    }

    private function collectPublicUrls(): array
    {
        if ($this->publicUrlsCache !== null) {
            return $this->publicUrlsCache;
        }

        $entries = [];
        $today = $this->today();

        $static = [
            ['/', 'VNV Events Home', 'daily', 1.0],
            ['/event-planners/', 'Event Planners', 'weekly', 0.9],
            ['/corporate-events/', 'Corporate Events', 'weekly', 0.8],
            ['/event-production/', 'Event Production', 'weekly', 0.8],
            ['/event-staffing/', 'Event Staffing', 'weekly', 0.7],
            ['/locations/', 'VNV Events Locations', 'weekly', 0.8],
            ['/blog/', 'VNV Events Blog', 'weekly', 0.7],
            ['/faq/', 'VNV Events FAQ', 'weekly', 0.8],
            ['/vnv-sessions/', 'VNV Sessions', 'weekly', 0.7],
        ];

        foreach ($static as [$path, $title, $freq, $priority]) {
            $entries[] = $this->entry($path, $title, $today, $freq, $priority, 'static');
        }

        $entries = array_merge(
            $entries,
            $this->collectPhysicalPublicPages(),
            $this->collectStoreContent(),
            $this->collectGrowthHubContent(),
            $this->collectLocations(),
            $this->collectCmsRoutes(),
            $this->collectCmsCategories(),
            $this->collectForums()
        );

        $deduped = [];
        foreach ($entries as $entry) {
            if (!$this->isValidPublicUrl($entry['loc'])) {
                continue;
            }

            if (isset($deduped[$entry['loc']]) && !$this->isBetterSitemapEntry($entry, $deduped[$entry['loc']])) {
                continue;
            }

            $deduped[$entry['loc']] = $entry;
        }

        ksort($deduped);
        $this->publicUrlsCache = array_values($deduped);
        return $this->publicUrlsCache;
    }

    private function groupSitemapUrls(array $urls): array
    {
        $groups = [
            'pages' => [],
            'blog' => [],
            'store' => [],
            'locations' => [],
        ];

        foreach ($urls as $url) {
            $type = (string)($url['type'] ?? '');

            if ($type === 'location') {
                $groups['locations'][] = $url;
                continue;
            }

            if ($type === 'blog') {
                $groups['blog'][] = $url;
                continue;
            }

            if (in_array($type, ['product', 'product_category'], true)) {
                $groups['store'][] = $url;
                continue;
            }

            $groups['pages'][] = $url;
        }

        return $groups;
    }

    private function collectPhysicalPublicPages(): array
    {
        $pagesRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'pages';
        if (!is_dir($pagesRoot)) {
            return [];
        }

        $excluded = [
            'blog-post',
            'blog-category',
            'cms-content',
            'growth-content',
            'location-page',
            'product',
            'product-category',
        ];

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pagesRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getFilename() !== 'index.php') {
                continue;
            }

            $directory = str_replace('\\', '/', substr($file->getPath(), strlen($pagesRoot) + 1));
            if ($directory === '' || $directory === false) {
                continue;
            }

            $segments = array_values(array_filter(explode('/', $directory)));
            if (!$segments || in_array($segments[0], $excluded, true)) {
                continue;
            }

            $path = '/' . implode('/', $segments) . '/';
            $entries[] = $this->entry(
                $path,
                $this->titleFromPath($path),
                date('Y-m-d', (int)$file->getMTime()),
                'monthly',
                $this->priorityForPhysicalPath($path),
                'static'
            );
        }

        return $entries;
    }

    private function collectStoreContent(): array
    {
        $entries = [];
        $ownerId = AvomealContext::ownerId();
        $siteKey = SiteContext::siteKey();

        try {
            $productsRepo = new StoreProductsRepository();
            foreach ($productsRepo->getPublicSitemapEntries(5000, $ownerId, $siteKey) as $product) {
                $entries[] = $this->entry(
                    '/product/' . trim((string)$product->slug, '/') . '/',
                    $product->name ?? 'Product',
                    $this->bestDate($product),
                    'weekly',
                    0.7,
                    'product'
                );
            }
        } catch (\Throwable $e) {
            error_log('SEO store product collection failed: ' . $e->getMessage());
        }

        try {
            $categoriesRepo = new StoreCategoriesRepository();
            foreach ($categoriesRepo->getPublicSitemapEntries(1000, $ownerId, $siteKey) as $category) {
                $entries[] = $this->entry(
                    '/product-category/' . trim((string)$category->slug, '/') . '/',
                    $category->name ?? 'Product category',
                    $this->bestDate($category),
                    'weekly',
                    0.65,
                    'product_category'
                );
            }
        } catch (\Throwable $e) {
            error_log('SEO store category collection failed: ' . $e->getMessage());
        }

        return $entries;
    }

    private function collectLocations(): array
    {
        try {
            $repo = new LocationPagesRepository();
            $siteKey = SiteContext::siteKey();
            return array_map(function ($page) {
                return $this->entry(
                    '/locations/' . trim((string)$page->slug, '/') . '/',
                    $page->title ?? $page->city ?? 'Location page',
                    $this->bestDate($page),
                    'weekly',
                    0.8,
                    'location'
                );
            }, $repo->getAllIndexablePublished($siteKey));
        } catch (\Throwable $e) {
            error_log('SEO location collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function collectGrowthHubContent(): array
    {
        try {
            $client = new OphyraGrowthHubClient();
            if (!$client->isConfigured()) {
                return [];
            }

            $entries = [];
            foreach (['page', 'landing', 'custom', 'location', 'blog'] as $type) {
                foreach ($client->contentList($type, 1000) as $content) {
                    $route = (string)($content['route'] ?? '');
                    if ($route === '') {
                        continue;
                    }

                    $entryType = $type === 'location' ? 'location' : ($type === 'blog' ? 'blog' : 'page');
                    $entries[] = $this->entry(
                        $route,
                        (string)($content['title'] ?? 'Growth Hub content'),
                        $this->bestDate((object)$content),
                        $entryType === 'blog' ? 'weekly' : 'monthly',
                        $entryType === 'location' ? 0.8 : ($entryType === 'blog' ? 0.7 : 0.6),
                        $entryType
                    );
                }
            }

            return $entries;
        } catch (\Throwable $e) {
            error_log('SEO Growth Hub collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function collectCmsRoutes(): array
    {
        try {
            $repo = new CmsContentsRepository();
            $repo->db = new Connection();
            $siteKey = SiteContext::siteKey();
            return array_map(function ($content) {
                $route = $content->route ?: ($content->canonical_url ?? null);
                $type = $this->typeForCmsContent($content);

                return $this->entry(
                    $route ?: '/' . trim((string)$content->slug, '/') . '/',
                    $content->title ?? 'Public page',
                    $this->bestDate($content),
                    in_array($type, ['blog', 'location'], true) ? 'weekly' : 'monthly',
                    $type === 'location' ? 0.8 : ($type === 'blog' ? 0.7 : 0.6),
                    $type
                );
            }, $repo->getPublishedSitemapEntries('en', $siteKey));
        } catch (\Throwable $e) {
            error_log('SEO CMS collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function collectCmsCategories(): array
    {
        try {
            $db = new Connection();
            if (!$this->tableExists($db, 'cms_categories') || !$this->tableExists($db, 'cms_contents')) {
                return [];
            }

            $entries = [];
            foreach ($this->cmsCategoryTargets($db) as $target) {
                foreach ($this->fetchCmsCategoriesForTarget($db, $target) as $category) {
                    $entries[] = $this->entry(
                        $target['path'] . trim((string)$category->slug, '/') . '/',
                        (string)($category->name ?? $category->slug ?? 'Category'),
                        $this->bestDate((object)[
                            'updated_at' => $category->content_updated_at ?? null,
                            'created_at' => $category->updated_at ?? null,
                        ]),
                        'weekly',
                        $target['priority'],
                        $target['type']
                    );
                }
            }

            return $entries;
        } catch (\Throwable $e) {
            error_log('SEO CMS category collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function cmsCategoryTargets(Connection $db): array
    {
        $hasContentType = $this->columnExists($db, 'cms_contents', 'content_type');
        $hasLegacyType = $this->columnExists($db, 'cms_contents', 'type');

        $typeSql = static function (array $contentTypes, ?string $legacyType = null) use ($hasContentType, $hasLegacyType): string {
            $parts = [];

            if ($hasContentType) {
                $quoted = array_map(static fn ($value) => "'" . str_replace("'", "''", $value) . "'", $contentTypes);
                $parts[] = "LOWER(COALESCE(c.content_type, '')) IN (" . implode(',', $quoted) . ")";
            }

            if ($hasLegacyType && $legacyType !== null) {
                $parts[] = "LOWER(COALESCE(c.type, '')) = '" . str_replace("'", "''", $legacyType) . "'";
            }

            return '(' . implode(' OR ', $parts ?: ['1=0']) . ')';
        };

        return [
            [
                'type' => 'blog',
                'path' => '/blog/',
                'applies_column' => 'applies_to_blog',
                'content_sql' => $typeSql(['blog', 'blog_post', 'post'], 'post'),
                'priority' => 0.65,
            ],
            [
                'type' => 'location',
                'path' => '/locations/',
                'applies_column' => 'applies_to_locations',
                'content_sql' => $typeSql(['location', 'locations', 'location_page', 'location-page'], null),
                'priority' => 0.75,
            ],
            [
                'type' => 'page',
                'path' => '/pages/',
                'applies_column' => 'applies_to_pages',
                'content_sql' => $typeSql(['page', 'landing', 'custom'], 'page'),
                'priority' => 0.55,
            ],
        ];
    }

    private function fetchCmsCategoriesForTarget(Connection $db, array $target): array
    {
        $hasCategorySiteKey = $this->columnExists($db, 'cms_categories', 'site_key');
        $hasContentSiteKey = $this->columnExists($db, 'cms_contents', 'site_key');
        $hasCategoryActive = $this->columnExists($db, 'cms_categories', 'is_active');
        $hasAppliesColumn = $this->columnExists($db, 'cms_categories', (string)$target['applies_column']);
        $contentSql = (string)$target['content_sql'];
        $contentSqlForLatest = str_replace('c.', 'c2.', $contentSql);

        $where = [
            "COALESCE(cc.slug, '') <> ''",
            "EXISTS (
                SELECT 1
                FROM cms_contents c
                WHERE c.id_cms_category = cc.id
                  AND c.status = 'PUBLISHED'
                  AND (c.robots IS NULL OR LOWER(c.robots) NOT LIKE '%noindex%')
                  AND {$contentSql}
                  {$this->siteScopeSql($db, 'cms_contents', 'c')}
            )",
        ];

        if ($hasCategoryActive) {
            $where[] = 'COALESCE(cc.is_active, 1) = 1';
        }

        if ($hasAppliesColumn) {
            $where[] = "COALESCE(cc.{$target['applies_column']}, 1) = 1";
        }

        $sql = "
            SELECT
                cc.id,
                cc.name,
                cc.slug,
                cc.updated_at,
                (
                    SELECT MAX(COALESCE(c2.updated_at, c2.published_at, c2.created_at))
                    FROM cms_contents c2
                    WHERE c2.id_cms_category = cc.id
                      AND c2.status = 'PUBLISHED'
                      AND {$contentSqlForLatest}
                      {$this->siteScopeSql($db, 'cms_contents', 'c2')}
                ) AS content_updated_at
            FROM cms_categories cc
            WHERE " . implode("\n              AND ", $where) . "
              {$this->siteScopeSql($db, 'cms_categories', 'cc')}
            ORDER BY cc.name ASC
        ";

        $db->query($sql);
        if ($hasCategorySiteKey || $hasContentSiteKey) {
            $db->bind(':site_key', SiteContext::siteKey());
        }

        return $db->fetchAll() ?: [];
    }

    private function typeForCmsContent(object $content): string
    {
        $contentType = strtolower(trim((string)($content->content_type ?? '')));

        if (in_array($contentType, ['blog', 'blog_post', 'post'], true)) {
            return 'blog';
        }

        if (in_array($contentType, ['location', 'locations', 'location_page', 'location-page'], true)) {
            return 'location';
        }

        return strtolower(trim((string)($content->type ?? ''))) === 'post' ? 'blog' : 'page';
    }

    private function siteScopeSql(Connection $db, string $table, string $alias): string
    {
        if (!$this->columnExists($db, $table, 'site_key')) {
            return '';
        }

        return " AND ({$alias}.site_key = :site_key OR {$alias}.site_key IN ('shared', 'global', 'all_sites'))";
    }

    private function tableExists(Connection $db, string $table): bool
    {
        try {
            $db->query("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
                LIMIT 1
            ");
            $db->bind(':table', $table);
            return (bool)$db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(Connection $db, string $table, string $column): bool
    {
        try {
            $db->query("SHOW COLUMNS FROM `{$table}` LIKE :column");
            $db->bind(':column', $column);
            return (bool)$db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function collectForums(): array
    {
        try {
            $repo = new ForumTopicRepository();
            $siteKey = SiteContext::siteKey();
            return array_map(function ($topic) {
                return $this->entry(
                    '/forums/' . trim((string)$topic->slug, '/') . '/',
                    $topic->title ?? 'Forum topic',
                    $this->bestDate($topic),
                    'weekly',
                    0.5,
                    'forum'
                );
            }, $repo->getPublishedSitemapEntries($siteKey));
        } catch (\Throwable $e) {
            error_log('SEO forum collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function entry(string $pathOrUrl, string $title, string $lastmod, string $changefreq, float $priority, string $type): array
    {
        return [
            'loc' => $this->absoluteUrl($pathOrUrl),
            'title' => trim(strip_tags($title)),
            'lastmod' => $this->formatDate($lastmod),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'type' => $type,
        ];
    }

    private function isBetterSitemapEntry(array $candidate, array $current): bool
    {
        $candidatePriority = (float)($candidate['priority'] ?? 0);
        $currentPriority = (float)($current['priority'] ?? 0);

        if ($candidatePriority !== $currentPriority) {
            return $candidatePriority > $currentPriority;
        }

        $frequencyRank = [
            'always' => 6,
            'hourly' => 5,
            'daily' => 4,
            'weekly' => 3,
            'monthly' => 2,
            'yearly' => 1,
            'never' => 0,
        ];

        $candidateFrequency = $frequencyRank[strtolower((string)($candidate['changefreq'] ?? ''))] ?? 0;
        $currentFrequency = $frequencyRank[strtolower((string)($current['changefreq'] ?? ''))] ?? 0;

        if ($candidateFrequency !== $currentFrequency) {
            return $candidateFrequency > $currentFrequency;
        }

        return strcmp((string)($candidate['lastmod'] ?? ''), (string)($current['lastmod'] ?? '')) > 0;
    }

    private function writeAndLog(string $fileType, string $filename, string $content, int $count, ?int $userId): array
    {
        $path = $this->path($filename);
        $status = 'success';
        $message = 'Generated successfully.';

        try {
            if (!is_dir($this->publicPath)) {
                throw new \RuntimeException('Public path not found.');
            }

            if (file_put_contents($path, $content, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write file.');
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $message = $e->getMessage();
        }

        $result = [
            'file_type' => $fileType,
            'status' => $status,
            'message' => $message,
            'items_count' => $count,
            'file_path' => $path,
            'public_url' => $this->absoluteUrl('/' . $filename),
        ];

        $this->logRepository?->record([
            ...$result,
            'generated_by' => $userId,
        ]);

        return $result;
    }

    private function appendSection(array &$lines, string $title, array $entries, int $limit): void
    {
        if (empty($entries)) {
            return;
        }

        $lines[] = '';
        $lines[] = '## ' . $title;
        $lines[] = '';

        foreach (array_slice($entries, 0, $limit) as $entry) {
            $lines[] = '- ' . $entry['title'] . ': ' . $entry['loc'];
        }
    }

    private function titleFromPath(string $path): string
    {
        $last = trim(basename(trim($path, '/')), '/');
        if ($last === '') {
            return 'VNV Events Home';
        }

        return ucwords(str_replace(['-', '_'], ' ', $last));
    }

    private function priorityForPhysicalPath(string $path): float
    {
        foreach (['/vnv-gourmet/', '/catering-stations-south-florida/', '/crepes-catering-south-florida/', '/pasta-station-catering-south-florida/', '/pizza-station-catering-south-florida/', '/paella-catering-south-florida/', '/taco-station-catering-south-florida/', '/corporate-catering-south-florida/', '/event-planners/', '/corporate-events/', '/event-production/', '/event-staffing/', '/locations/', '/blog/'] as $important) {
            if ($path === $important) {
                return 0.8;
            }
        }

        return 0.55;
    }

    private function aggregateStatus(array $results): string
    {
        $statuses = array_values(array_map(fn ($item) => $item['status'] ?? 'failed', $results));
        if (count(array_unique($statuses)) === 1 && ($statuses[0] ?? null) === 'success') {
            return 'success';
        }

        return in_array('success', $statuses, true) ? 'partial' : 'failed';
    }

    private function absoluteUrl(string $pathOrUrl): string
    {
        $value = trim($pathOrUrl);
        if ($value === '') {
            return SiteContext::publicBaseUrl() . '/';
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH) ?: '/';
            return SiteContext::publicBaseUrl() . $this->normalizePath($path);
        }

        return SiteContext::publicBaseUrl() . $this->normalizePath($value);
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/' && !str_contains(basename($path), '.')) {
            $path = rtrim($path, '/') . '/';
        }

        return $path;
    }

    private function isValidPublicUrl(string $url): bool
    {
        if (!str_starts_with($url, SiteContext::publicBaseUrl())) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        foreach (['/panel/', '/api/', '/storage/private/', '/login', '/signup'] as $blocked) {
            if (str_starts_with($path, $blocked)) {
                return false;
            }
        }

        return str_contains($url, ' ') === false;
    }

    private function bestDate(object $row): string
    {
        return (string)($row->updated_at ?? $row->published_at ?? $row->created_at ?? date('Y-m-d'));
    }

    private function formatDate(?string $date): string
    {
        $timestamp = $date ? strtotime($date) : false;
        return $timestamp ? date('Y-m-d', $timestamp) : $this->today();
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m-d');
    }

    private function path(string $filename): string
    {
        return $this->publicPath . DIRECTORY_SEPARATOR . $filename;
    }
}
