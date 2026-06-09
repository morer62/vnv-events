<?php

namespace App\Services;

class OphyraGrowthHubClient
{
    private string $baseUrl;
    private string $siteKey;
    private int $timeout;
    private array $cache = [];

    public function __construct(?string $baseUrl = null, ?string $siteKey = null)
    {
        $this->baseUrl = rtrim(trim((string)($baseUrl
            ?? $_ENV['OPHYRA_BASE_URL']
            ?? $_ENV['OPHYRA_GROWTH_HUB_BASE_URL']
            ?? $_ENV['GROWTH_HUB_BASE_URL']
            ?? '')), '/');

        $configuredSiteKey = trim((string)($siteKey
            ?? $_ENV['OPHYRA_GROWTH_SITE_KEY']
            ?? $_ENV['GROWTH_HUB_SITE_KEY']
            ?? $_ENV['SEO_AGENT_DEFAULT_SITE_KEY']
            ?? 'vnvevents'));

        $this->siteKey = $this->normalizeSiteKey($configuredSiteKey);
        $this->timeout = max(1, (int)($_ENV['OPHYRA_GROWTH_TIMEOUT'] ?? 4));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function contentByRoute(string $route): ?array
    {
        $normalizedRoute = $this->normalizeRoute($route);
        $candidateRoutes = [$normalizedRoute];

        if ($normalizedRoute !== '/') {
            $candidateRoutes[] = $normalizedRoute . '/';
        }

        foreach (array_unique($candidateRoutes) as $candidateRoute) {
            $content = $this->validatedContent($this->get('/api/growth-hub/content', [
                'site_key' => $this->siteKey,
                'route' => $candidateRoute,
            ]));

            if ($content) {
                return $content;
            }
        }

        return null;
    }

    public function contentBySlug(string $slug, ?string $type = null): ?array
    {
        $query = [
            'site_key' => $this->siteKey,
            'slug' => trim($slug, '/'),
        ];

        if ($type) {
            $query['type'] = $type;
        }

        return $this->validatedContent($this->get('/api/growth-hub/content', $query));
    }

    public function contentList(?string $type = null, int $limit = 60): array
    {
        $query = ['site_key' => $this->siteKey];
        if ($type) {
            $query['type'] = $type;
        }

        $payload = $this->get('/api/growth-hub/content', $query);
        $items = $this->extractList($payload);

        $valid = [];
        foreach ($items as $item) {
            $content = $this->validatedContent($item);
            if (!$content) {
                continue;
            }
            if ($type && $this->normalizeContentType((string)($content['content_type'] ?? 'page')) !== $this->normalizeContentType($type)) {
                continue;
            }
            $content['content_type'] = $this->normalizeContentType((string)($content['content_type'] ?? 'page'));
            $valid[] = $content;
        }

        return array_slice($valid, 0, $limit);
    }

    public function routes(): array
    {
        $payload = $this->get('/api/growth-hub/routes', ['site_key' => $this->siteKey]);
        $items = $this->extractList($payload);

        return array_values(array_filter($items, function ($route) {
            return is_array($route)
                && $this->normalizeSiteKey((string)($route['site_key'] ?? $this->siteKey)) === $this->siteKey
                && !empty($route['route']);
        }));
    }

    public function sitemapXml(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = $this->baseUrl . '/api/growth-hub/sitemap?' . http_build_query(['site_key' => $this->siteKey]);
        $response = $this->request($url);

        if (!$response || stripos($response, '<urlset') === false) {
            return null;
        }

        return $response;
    }

    public function publicBaseUrl(): string
    {
        return rtrim((string)($_ENV['PUBLIC_BASE_URL'] ?? $_ENV['SITE_PUBLIC_BASE_URL'] ?? $_ENV['APP_URL'] ?? 'https://vnvevents.com'), '/');
    }

    private function get(string $path, array $query): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = $this->baseUrl . $path . '?' . http_build_query($query);
        $cacheKey = sha1($url);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $response = $this->request($url);
        if (!$response) {
            return $this->cache[$cacheKey] = null;
        }

        $decoded = json_decode($response, true);
        return $this->cache[$cacheKey] = (is_array($decoded) ? $decoded : null);
    }

    private function request(string $url): ?string
    {
        $ch = curl_init($url);
        if (!$ch) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            if ($error !== '') {
                error_log('Ophyra Growth Hub request failed: ' . $error);
            }
            return null;
        }

        return (string)$response;
    }

    private function validatedContent(?array $payload): ?array
    {
        if (!$payload || !empty($payload['error'])) {
            return null;
        }

        $content = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : $payload;
        if (!is_array($content) || empty($content['id']) || empty($content['route'])) {
            return null;
        }

        if ($this->normalizeSiteKey((string)($content['site_key'] ?? '')) !== $this->siteKey) {
            return null;
        }

        $status = strtoupper((string)($content['status'] ?? 'PUBLISHED'));
        $approval = strtoupper((string)($content['approval_status'] ?? 'APPROVED'));
        if ($status !== 'PUBLISHED' || !in_array($approval, ['APPROVED', 'PUBLISHED'], true)) {
            return null;
        }

        $rawType = (string)($content['content_type'] ?? $content['type'] ?? 'page');
        $content['content_type'] = $this->normalizeContentType($rawType);

        return $content;
    }

    private function extractList(?array $payload): array
    {
        if (!$payload || !empty($payload['error'])) {
            return [];
        }

        foreach (['items', 'content', 'routes', 'data', 'results'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }

            return array_values($payload[$key]);
        }

        return array_is_list($payload) ? $payload : [];
    }

    private function normalizeRoute(string $route): string
    {
        $route = '/' . trim($route, '/');
        return $route === '/' ? '/' : rtrim($route, '/');
    }

    private function normalizeSiteKey(string $siteKey): string
    {
        $siteKey = strtolower(trim($siteKey));
        return $siteKey === 'vnv_events' || $siteKey === 'vnv-events' ? 'vnvevents' : $siteKey;
    }

    private function normalizeContentType(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));

        if ($contentType === 'location') {
            return 'location';
        }

        if (in_array($contentType, ['location_page', 'location-page', 'locations'], true)) {
            return 'location';
        }

        if (in_array($contentType, ['blog', 'post', 'blog_post', 'guide', 'faq_page', 'comparison', 'case_study', 'blog-post'], true)) {
            return 'blog';
        }

        return 'page';
    }
}
