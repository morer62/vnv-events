<?php

namespace App\Services;

class PublicSeoService
{
    private const SITE_URL = 'https://vnvevents.com';
    private const SITE_NAME = 'VNV Events';
    private const LOGO_URL = 'https://vnvevents.com/assets/images/planner-hub-logo-negative.png';

    public static function locationSeo(object $page): array
    {
        $canonical = self::canonical($page->canonical_url ?? null, '/' . trim((string)($page->slug ?? ''), '/') . '/');
        $title = self::firstFilled([
            $page->meta_title ?? null,
            $page->hero_title ?? null,
            ($page->title ?? '') ? $page->title . ' | VNV Events' : null,
        ]);
        $description = self::firstFilled([
            $page->meta_description ?? null,
            $page->excerpt ?? null,
            ($page->city ?? '') ? 'Professional event planning, rentals, decor, DJ, photo and video services for events in ' . $page->city . '.' : null,
        ]);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => self::robots(($page->status ?? '') === 'PUBLISHED' && (int)($page->is_indexable ?? 1) === 1),
            'og_type' => 'website',
            'og_image' => self::absoluteUrl(self::firstFilled([$page->og_image ?? null, $page->hero_image ?? null, self::LOGO_URL])),
            'og_image_alt' => self::clean($page->title ?? self::SITE_NAME),
        ];
    }

    public static function locationSchema(object $page, array $faqs = []): array
    {
        $canonical = self::canonical($page->canonical_url ?? null, '/' . trim((string)($page->slug ?? ''), '/') . '/');
        $title = self::firstFilled([$page->meta_title ?? null, $page->hero_title ?? null, $page->title ?? null]);
        $description = self::firstFilled([$page->meta_description ?? null, $page->excerpt ?? null]);
        $city = self::clean($page->city ?? '');
        $state = self::clean($page->state ?? '');
        $areaName = trim($city . ($state ? ', ' . $state : ''));

        $graph = [
            self::organizationNode(),
            self::websiteNode(),
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'isPartOf' => ['@id' => self::SITE_URL . '/#website'],
                'about' => ['@id' => self::SITE_URL . '/#organization'],
                'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
                'inLanguage' => 'en-US',
            ],
            [
                '@type' => 'Service',
                '@id' => $canonical . '#service',
                'name' => self::firstFilled([
                    $page->primary_keyword ?? null,
                    $city ? 'Event Planning Services in ' . $city : 'Event Planning Services',
                ]),
                'provider' => ['@id' => self::SITE_URL . '/#organization'],
                'areaServed' => $city ? [
                    '@type' => 'City',
                    'name' => $areaName ?: $city,
                ] : [
                    '@type' => 'AdministrativeArea',
                    'name' => 'South Florida',
                ],
                'serviceType' => 'Event planning, event rentals, decor, DJ, photo and video',
                'url' => $canonical,
            ],
            self::breadcrumbNode($canonical, [
                ['name' => 'Home', 'url' => self::SITE_URL . '/'],
                ['name' => 'Locations', 'url' => self::SITE_URL . '/locations/'],
                ['name' => $city ?: ($page->title ?? 'Location'), 'url' => $canonical],
            ]),
        ];

        $faqNode = self::faqNode($canonical, $faqs);
        if ($faqNode) {
            $graph[] = $faqNode;
        }

        return self::schema($graph);
    }

    public static function contentSeo(object $content, object $route, string $fallbackType = 'page'): array
    {
        $canonical = self::canonical($content->canonical_url ?? null, $route->route ?? ('/' . trim((string)($content->slug ?? ''), '/') . '/'));
        $titleSuffix = $fallbackType === 'post' ? ' | VNV Events Blog' : ' | VNV Events';

        return [
            'title' => self::firstFilled([
                $content->meta_title ?? null,
                ($content->title ?? '') ? $content->title . $titleSuffix : null,
            ]),
            'description' => self::firstFilled([
                $content->meta_description ?? null,
                $content->excerpt ?? null,
                $fallbackType === 'post' ? 'VNV Events blog article.' : 'VNV Events public page.',
            ]),
            'canonical' => $canonical,
            'robots' => self::robots(($content->status ?? '') === 'PUBLISHED', $content->robots ?? null),
            'og_type' => $fallbackType === 'post' ? 'article' : 'website',
            'og_image' => self::absoluteUrl(self::firstFilled([$content->featured_image_url ?? null, self::LOGO_URL])),
            'og_image_alt' => self::clean($content->title ?? self::SITE_NAME),
        ];
    }

    public static function blogSchema(object $post, object $route, ?object $category = null): array
    {
        $canonical = self::canonical($post->canonical_url ?? null, $route->route ?? ('/' . trim((string)($post->slug ?? ''), '/') . '/'));
        $graph = [
            self::organizationNode(),
            self::websiteNode(),
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => self::firstFilled([$post->meta_title ?? null, $post->title ?? null]),
                'description' => self::firstFilled([$post->meta_description ?? null, $post->excerpt ?? null]),
                'isPartOf' => ['@id' => self::SITE_URL . '/#website'],
                'about' => ['@id' => self::SITE_URL . '/#organization'],
                'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
                'inLanguage' => 'en-US',
            ],
            [
                '@type' => 'BlogPosting',
                '@id' => $canonical . '#article',
                'headline' => self::clean($post->title ?? ''),
                'description' => self::firstFilled([$post->meta_description ?? null, $post->excerpt ?? null]),
                'datePublished' => self::dateIso($post->published_at ?? $post->created_at ?? null),
                'dateModified' => self::dateIso($post->updated_at ?? $post->published_at ?? null),
                'mainEntityOfPage' => ['@id' => $canonical . '#webpage'],
                'publisher' => ['@id' => self::SITE_URL . '/#organization'],
            ],
            self::breadcrumbNode($canonical, [
                ['name' => 'Home', 'url' => self::SITE_URL . '/'],
                ['name' => 'Blog', 'url' => self::SITE_URL . '/blog/'],
                ['name' => $category->name ?? 'Article', 'url' => $category ? self::SITE_URL . '/category/blog/' . trim((string)$category->slug, '/') . '/' : $canonical],
                ['name' => $post->title ?? 'Article', 'url' => $canonical],
            ]),
        ];

        if (!empty($post->featured_image_url)) {
            $graph[] = self::imageNode($canonical . '#primaryimage', $post->featured_image_url, $post->title ?? '');
            $graph[3]['image'] = ['@id' => $canonical . '#primaryimage'];
        }

        return self::schema($graph);
    }

    public static function pageSchema(object $page, object $route, array $contentJson = []): array
    {
        $canonical = self::canonical($page->canonical_url ?? null, $route->route ?? ('/' . trim((string)($page->slug ?? ''), '/') . '/'));
        $graph = [
            self::organizationNode(),
            self::websiteNode(),
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => self::firstFilled([$page->meta_title ?? null, $page->title ?? null]),
                'description' => self::firstFilled([$page->meta_description ?? null, $page->excerpt ?? null]),
                'isPartOf' => ['@id' => self::SITE_URL . '/#website'],
                'about' => ['@id' => self::SITE_URL . '/#organization'],
                'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
                'inLanguage' => 'en-US',
            ],
            self::breadcrumbNode($canonical, [
                ['name' => 'Home', 'url' => self::SITE_URL . '/'],
                ['name' => $page->title ?? 'Page', 'url' => $canonical],
            ]),
        ];

        if (self::looksLikeServicePage($page, $contentJson)) {
            $graph[] = [
                '@type' => 'Service',
                '@id' => $canonical . '#service',
                'name' => self::clean($page->title ?? 'VNV Events Service'),
                'provider' => ['@id' => self::SITE_URL . '/#organization'],
                'areaServed' => [
                    '@type' => 'AdministrativeArea',
                    'name' => 'South Florida',
                ],
                'serviceType' => self::clean($page->title ?? 'Event services'),
                'url' => $canonical,
            ];
        }

        $faqNode = self::faqNode($canonical, self::extractFaqs($contentJson));
        if ($faqNode) {
            $graph[] = $faqNode;
        }

        return self::schema($graph);
    }

    public static function forumTopicSeo(object $topic): array
    {
        $canonical = self::absoluteUrl('/forums/' . trim((string)($topic->slug ?? ('topic-' . $topic->id)), '/') . '/');
        $description = self::firstFilled([
            $topic->seo_description ?? null,
            $topic->excerpt ?? null,
            substr(self::clean($topic->content ?? ''), 0, 155),
        ]);

        return [
            'title' => self::firstFilled([
                $topic->seo_title ?? null,
                ($topic->title ?? '') ? $topic->title . ' | VNV Events Community' : null,
            ]),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => (($topic->status ?? 'PUBLISHED') === 'PUBLISHED' && (int)($topic->is_approved ?? 1) === 1)
                ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
                : 'noindex,follow',
            'og_type' => 'article',
            'og_image' => self::LOGO_URL,
            'og_image_alt' => self::SITE_NAME . ' community discussion',
        ];
    }

    public static function forumTopicSchema(object $topic): array
    {
        $canonical = self::absoluteUrl('/forums/' . trim((string)($topic->slug ?? ('topic-' . $topic->id)), '/') . '/');

        return self::schema([
            self::organizationNode(),
            self::websiteNode(),
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => self::firstFilled([$topic->seo_title ?? null, $topic->title ?? null]),
                'description' => self::firstFilled([$topic->seo_description ?? null, $topic->excerpt ?? null]),
                'isPartOf' => ['@id' => self::SITE_URL . '/#website'],
                'about' => ['@id' => self::SITE_URL . '/#organization'],
                'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
                'inLanguage' => 'en-US',
            ],
            [
                '@type' => 'DiscussionForumPosting',
                '@id' => $canonical . '#discussion',
                'headline' => self::clean($topic->title ?? ''),
                'text' => self::clean($topic->content ?? ''),
                'datePublished' => self::dateIso($topic->published_at ?? $topic->created_at ?? null),
                'dateModified' => self::dateIso($topic->updated_at ?? $topic->published_at ?? null),
                'author' => [
                    '@type' => 'Person',
                    'name' => self::clean(trim(($topic->user_name ?? '') . ' ' . ($topic->user_lastname ?? ''))) ?: self::SITE_NAME,
                ],
                'publisher' => ['@id' => self::SITE_URL . '/#organization'],
                'mainEntityOfPage' => ['@id' => $canonical . '#webpage'],
            ],
            self::breadcrumbNode($canonical, [
                ['name' => 'Home', 'url' => self::SITE_URL . '/'],
                ['name' => 'Forums', 'url' => self::SITE_URL . '/forums/'],
                ['name' => $topic->title ?? 'Discussion', 'url' => $canonical],
            ]),
        ]);
    }

    public static function forumListSeo(): array
    {
        return [
            'title' => 'VNV Events Community Forums | Event Planning Q&A',
            'description' => 'Public VNV Events community discussions for event planning ideas, venues, rentals, quinceañeras, weddings and corporate events in South Florida.',
            'canonical' => self::SITE_URL . '/forums/',
            'robots' => 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'og_type' => 'website',
            'og_image' => self::LOGO_URL,
            'og_image_alt' => self::SITE_NAME . ' community forums',
        ];
    }

    public static function defaultInternalLinks(): array
    {
        return [
            ['label' => 'Home', 'url' => self::SITE_URL . '/'],
            ['label' => 'Request a Quote', 'url' => self::SITE_URL . '/event-planners/'],
            ['label' => 'Service Areas', 'url' => self::SITE_URL . '/service-areas-in-south-florida/'],
            ['label' => 'Corporate Events', 'url' => self::SITE_URL . '/corporate-events/'],
            ['label' => 'Locations', 'url' => self::SITE_URL . '/locations/'],
        ];
    }

    private static function schema(array $graph): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values(array_filter($graph)),
        ];
    }

    private static function organizationNode(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => self::SITE_URL . '/#organization',
            'name' => self::SITE_NAME,
            'url' => self::SITE_URL,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => self::LOGO_URL,
            ],
            'email' => 'info@vnvevents.com',
            'telephone' => '+1 305-204-5427',
        ];
    }

    private static function websiteNode(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::SITE_URL . '/#website',
            'url' => self::SITE_URL,
            'name' => self::SITE_NAME,
            'publisher' => ['@id' => self::SITE_URL . '/#organization'],
            'inLanguage' => 'en-US',
        ];
    }

    private static function breadcrumbNode(string $canonical, array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => self::clean($item['name'] ?? ''),
                'item' => self::absoluteUrl($item['url'] ?? $canonical),
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => $elements,
        ];
    }

    private static function faqNode(string $canonical, array $faqs): ?array
    {
        $entities = [];
        foreach ($faqs as $faq) {
            $question = self::clean($faq['question'] ?? '');
            $answer = self::clean($faq['answer'] ?? '');
            if ($question === '' || $answer === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        if (!$entities) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $canonical . '#faq',
            'mainEntity' => $entities,
        ];
    }

    private static function imageNode(string $id, string $url, string $caption = ''): array
    {
        return [
            '@type' => 'ImageObject',
            '@id' => $id,
            'url' => self::absoluteUrl($url),
            'caption' => self::clean($caption),
        ];
    }

    private static function extractFaqs(array $contentJson): array
    {
        $faqs = [];
        foreach (($contentJson['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'faq' && !empty($block['items']) && is_array($block['items'])) {
                $faqs = array_merge($faqs, $block['items']);
            }
        }

        return $faqs;
    }

    private static function looksLikeServicePage(object $page, array $contentJson): bool
    {
        $haystack = strtolower(($page->title ?? '') . ' ' . ($page->slug ?? '') . ' ' . ($page->excerpt ?? ''));
        foreach (['service', 'event', 'wedding', 'corporate', 'production', 'catering', 'rental', 'photo', 'dj'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        foreach (($contentJson['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'service') {
                return true;
            }
        }

        return false;
    }

    private static function canonical(?string $stored, string $fallbackPath): string
    {
        return self::absoluteUrl(self::firstFilled([$stored, $fallbackPath]));
    }

    private static function robots(bool $indexable, ?string $stored = null): string
    {
        if ($stored && stripos($stored, 'noindex') !== false) {
            return 'noindex,follow';
        }

        return $indexable ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1' : 'noindex,follow';
    }

    private static function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = self::clean($value);
            if ($value !== '') {
                return $value;
            }
        }

        return self::SITE_NAME;
    }

    private static function clean($value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string)$value)) ?? '');
    }

    private static function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::SITE_URL . '/';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return self::SITE_URL . '/' . ltrim($url, '/');
    }

    private static function dateIso($date): ?string
    {
        if (!$date) {
            return null;
        }

        $timestamp = strtotime((string)$date);
        return $timestamp ? date('c', $timestamp) : null;
    }
}
