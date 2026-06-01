<?php

namespace App\Services;

use App\Repositories\CmsContentsRepository;
use App\Repositories\Connection;
use App\Repositories\ForumTopicRepository;
use App\Repositories\LocationPagesRepository;
use App\Repositories\SeoFilesLogRepository;
use App\Repositories\StoreCategoriesRepository;
use App\Repositories\StoreProductsRepository;
use DOMDocument;

class SeoFilesGeneratorService
{
    private const BASE_URL = 'https://vnvevents.com';

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
            'sitemap' => ['label' => 'sitemap.xml', 'filename' => 'sitemap.xml'],
            'robots' => ['label' => 'robots.txt', 'filename' => 'robots.txt'],
            'llms' => ['label' => 'llms.txt', 'filename' => 'llms.txt'],
            'llms_full' => ['label' => 'llms-full.txt', 'filename' => 'llms-full.txt'],
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
            ];
        }

        return $cards;
    }

    public function buildAudit(): array
    {
        $urls = $this->collectPublicUrls();
        $latest = $this->logRepository?->getLatest();

        return [
            'total_detected' => count($urls),
            'last_generation' => $latest->created_at ?? null,
        ];
    }

    private function generateSitemap(?int $userId): array
    {
        $urls = $this->collectPublicUrls();
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

        return $this->writeAndLog('sitemap', 'sitemap.xml', $dom->saveXML() ?: '', count($urls), $userId);
    }

    private function generateRobots(?int $userId): array
    {
        $content = implode("\n", [
            'User-agent: *',
            'Disallow: /panel/',
            'Disallow: /api/',
            'Disallow: /login',
            'Disallow: /signup',
            'Disallow: /storage/private/',
            '',
            'Sitemap: ' . $this->absoluteUrl('/sitemap.xml'),
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
        $forums = array_values(array_filter($urls, fn ($url) => ($url['type'] ?? '') === 'forum'));
        $store = array_values(array_filter($urls, fn ($url) => in_array(($url['type'] ?? ''), ['product', 'product_category'], true)));

        $lines = [
            '# VNV Events',
            '',
            'VNV Events is a South Florida event planning and event production company serving Miami-Dade, Broward and Palm Beach.',
            '',
            '## Main Services',
            '',
            '- Wedding planning',
            '- Corporate events',
            '- Quinceaneras',
            '- Baby showers',
            '- Event rentals',
            '- Flowers and decor',
            '- DJ, sound and lighting',
            '- Photo and video',
            '- Catering coordination',
            '',
            '## Main Public Pages',
            '',
            '- Home: ' . $this->absoluteUrl('/'),
            '- Services: ' . $this->absoluteUrl('/service-areas-in-south-florida/'),
            '- Locations: ' . $this->absoluteUrl('/locations/'),
            '- Forums: ' . $this->absoluteUrl('/forums/'),
            '- Contact / Quote: ' . $this->absoluteUrl('/contact/'),
            '',
            '## Main Service Areas',
            '',
        ];

        $serviceAreas = array_slice($locations, 0, $full ? 80 : 15);
        if (empty($serviceAreas)) {
            foreach (['Miami-Dade', 'Broward', 'Palm Beach', 'Miami', 'Doral', 'Fort Lauderdale', 'West Palm Beach'] as $area) {
                $lines[] = '- ' . $area;
            }
        } else {
            foreach ($serviceAreas as $location) {
                $lines[] = '- ' . $location['title'] . ': ' . $location['loc'];
            }
        }

        if ($full) {
            $this->appendSection($lines, 'Public Pages', $pages, 80);
            $this->appendSection($lines, 'Blog and Guides', $posts, 80);
            $this->appendSection($lines, 'Store and Rentals', $store, 80);
            $this->appendSection($lines, 'Public Forums', $forums, 60);
            $lines[] = '## Public SEO Files';
            $lines[] = '';
            $lines[] = '- Sitemap: ' . $this->absoluteUrl('/sitemap.xml');
            $lines[] = '- Robots: ' . $this->absoluteUrl('/robots.txt');
            $lines[] = '- LLMs: ' . $this->absoluteUrl('/llms.txt');
            $lines[] = '';
            $lines[] = 'Do not use private panel, order, customer, or administrative data as public context.';
        } else {
            $this->appendSection($lines, 'Featured Public Content', array_slice($posts, 0, 8), 8);
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
            ['/', 'Home | VNV Events', 'daily', 1.0],
            ['/locations/', 'Locations | VNV Events', 'weekly', 0.9],
            ['/forums/', 'VNV Events Forums', 'daily', 0.7],
            ['/service-areas-in-south-florida/', 'Service Areas in South Florida', 'weekly', 0.8],
            ['/corporate-events/', 'Corporate Events', 'weekly', 0.8],
            ['/event-planners/', 'Event Planners', 'weekly', 0.8],
            ['/event-production/', 'Event Production', 'weekly', 0.8],
            ['/vnv-events-productions/', 'VNV Events Productions', 'weekly', 0.8],
            ['/vnv-gourmet/', 'VNV Gourmet', 'weekly', 0.7],
            ['/vnv-live/', 'VNV Live', 'weekly', 0.7],
            ['/vnv-deco-flowers/', 'VNV Deco Flowers', 'weekly', 0.7],
        ];

        foreach ($static as [$path, $title, $freq, $priority]) {
            $entries[] = $this->entry($path, $title, $today, $freq, $priority, 'static');
        }

        $entries = array_merge(
            $entries,
            $this->collectLocations(),
            $this->collectCmsRoutes(),
            $this->collectStore(),
            $this->collectForums()
        );

        $deduped = [];
        foreach ($entries as $entry) {
            if (!$this->isValidPublicUrl($entry['loc'])) {
                continue;
            }
            $deduped[$entry['loc']] = $entry;
        }

        ksort($deduped);
        $this->publicUrlsCache = array_values($deduped);
        return $this->publicUrlsCache;
    }

    private function collectLocations(): array
    {
        try {
            $repo = new LocationPagesRepository();
            return array_map(function ($page) {
                return $this->entry(
                    '/' . trim((string)$page->slug, '/') . '/',
                    $page->title ?? $page->city ?? 'Location page',
                    $this->bestDate($page),
                    'weekly',
                    0.8,
                    'location'
                );
            }, $repo->getAllIndexablePublished());
        } catch (\Throwable $e) {
            error_log('SEO location collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function collectCmsRoutes(): array
    {
        try {
            $repo = new CmsContentsRepository();
            $repo->db = new Connection();
            return array_map(function ($content) {
                $route = $content->canonical_url ?: ($content->route ?? null);
                $type = ($content->type ?? '') === 'post' ? 'blog' : 'page';

                return $this->entry(
                    $route ?: '/' . trim((string)$content->slug, '/') . '/',
                    $content->title ?? 'Public page',
                    $this->bestDate($content),
                    $type === 'blog' ? 'weekly' : 'monthly',
                    $type === 'blog' ? 0.7 : 0.6,
                    $type
                );
            }, $repo->getPublishedSitemapEntries('en'));
        } catch (\Throwable $e) {
            error_log('SEO CMS collection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function collectStore(): array
    {
        $entries = [];

        try {
            $categories = (new StoreCategoriesRepository())->getPublicSitemapEntries();
            foreach ($categories as $category) {
                $entries[] = $this->entry(
                    '/product-category/' . trim((string)$category->slug, '/') . '/',
                    $category->name ?? 'Product category',
                    $this->bestDate($category),
                    'weekly',
                    0.6,
                    'product_category'
                );
            }
        } catch (\Throwable $e) {
            error_log('SEO store category collection failed: ' . $e->getMessage());
        }

        try {
            $products = (new StoreProductsRepository())->getPublicSitemapEntries();
            foreach ($products as $product) {
                $entries[] = $this->entry(
                    '/product/' . trim((string)$product->slug, '/') . '/',
                    $product->name ?? 'Product',
                    $this->bestDate($product),
                    'weekly',
                    0.6,
                    'product'
                );
            }
        } catch (\Throwable $e) {
            error_log('SEO store product collection failed: ' . $e->getMessage());
        }

        return $entries;
    }

    private function collectForums(): array
    {
        try {
            $repo = new ForumTopicRepository();
            return array_map(function ($topic) {
                return $this->entry(
                    '/forums/' . trim((string)$topic->slug, '/') . '/',
                    $topic->title ?? 'Forum topic',
                    $this->bestDate($topic),
                    'weekly',
                    0.5,
                    'forum'
                );
            }, $repo->getPublishedSitemapEntries());
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
            return self::BASE_URL . '/';
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH) ?: '/';
            return self::BASE_URL . $this->normalizePath($path);
        }

        return self::BASE_URL . $this->normalizePath($value);
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
        if (!str_starts_with($url, self::BASE_URL)) {
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
