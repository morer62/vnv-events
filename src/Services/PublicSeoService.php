<?php

namespace App\Services;

class PublicSeoService
{
    private const SITE_URL = 'https://vnvevents.com';
    private const SITE_NAME = 'VNV Events';
    private const LOGO_URL = 'https://vnvevents.com/assets/images/planner-hub-logo-negative.png';
    private const LOCAL_BUSINESS_ID = 'https://vnvevents.com/#localbusiness';
    private const WEBSITE_ID = 'https://vnvevents.com/#website';
    private const GOOGLE_BUSINESS_PROFILE_URL = 'https://share.google/dQqX7hhKBHLVaZaqQ';

    public static function locationSeo(object $page): array
    {
        $canonical = self::canonical($page->canonical_url ?? null, '/locations/' . trim((string)($page->slug ?? ''), '/') . '/');
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
        $canonical = self::canonical($page->canonical_url ?? null, '/locations/' . trim((string)($page->slug ?? ''), '/') . '/');
        $title = self::firstFilled([$page->meta_title ?? null, $page->hero_title ?? null, $page->title ?? null]);
        $description = self::firstFilled([$page->meta_description ?? null, $page->excerpt ?? null]);
        $city = self::clean($page->city ?? '');
        $state = self::clean($page->state ?? '');
        $areaName = trim($city . ($state ? ', ' . $state : ''));

        $graph = [
            self::organizationNode(),
            self::websiteNode(),
            self::locationBusinessNode($canonical, $page, $areaName),
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'isPartOf' => ['@id' => self::WEBSITE_ID],
                'about' => ['@id' => $canonical . '#localbusiness'],
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
                'provider' => ['@id' => self::LOCAL_BUSINESS_ID],
                'areaServed' => $city ? [[
                    '@type' => 'City',
                    'name' => $areaName ?: $city,
                ]] : [[
                    '@type' => 'AdministrativeArea',
                    'name' => 'South Florida',
                ]],
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

    public static function homepageSchema(array $seo = []): array
    {
        $canonical = self::SITE_URL . '/';
        return self::schema([
            self::organizationNode(),
            self::websiteNode(),
            self::webPageNode(
                $canonical,
                self::firstFilled([$seo['title'] ?? null, 'VNV Events | South Florida Event Planning, Catering and Production']),
                self::firstFilled([
                    $seo['description'] ?? null,
                    'VNV Events is a full-service luxury event planning, catering, production, entertainment and event rental studio serving Miami, Broward, Palm Beach and South Florida.',
                ])
            ),
        ]);
    }

    public static function productSchema(object $product, array $faqs = []): array
    {
        $canonical = self::SITE_URL . '/product/' . trim((string)($product->slug ?? ''), '/') . '/';
        $name = self::clean($product->name ?? 'VNV Events Product');
        $description = self::firstFilled([
            $product->short_description ?? null,
            $product->description ?? null,
            $name,
        ]);
        $image = self::absoluteUrl(self::firstFilled([$product->main_image ?? null, self::LOGO_URL]));

        $productNode = [
            '@type' => 'Product',
            '@id' => $canonical . '#product',
            'name' => $name,
            'url' => $canonical,
            'description' => $description,
            'image' => [$image],
            'brand' => ['@id' => self::LOCAL_BUSINESS_ID],
            'seller' => ['@id' => self::LOCAL_BUSINESS_ID],
            'category' => self::productCategoryName($product),
            'offers' => self::productOffersNode($product, $canonical),
        ];

        $sku = self::clean($product->sku ?? '');
        if ($sku !== '') {
            $productNode['sku'] = $sku;
        }

        $graph = [
            self::organizationNode(),
            self::websiteNode(),
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $name . ' | VNV Events Store',
                'description' => $description,
                'isPartOf' => ['@id' => self::WEBSITE_ID],
                'about' => ['@id' => $canonical . '#product'],
                'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
                'inLanguage' => 'en-US',
            ],
            $productNode,
            self::breadcrumbNode($canonical, [
                ['name' => 'Home', 'url' => self::SITE_URL . '/'],
                ['name' => 'Store', 'url' => self::SITE_URL . '/store-categories/'],
                ['name' => self::productCategoryName($product), 'url' => self::productCategoryUrl($product)],
                ['name' => $name, 'url' => $canonical],
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
                'isPartOf' => ['@id' => self::WEBSITE_ID],
                'about' => ['@id' => self::LOCAL_BUSINESS_ID],
                'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
                'inLanguage' => 'en-US',
            ],
            [
                '@type' => 'BlogPosting',
                '@id' => $canonical . '#blogposting',
                'headline' => self::clean($post->title ?? ''),
                'description' => self::firstFilled([$post->meta_description ?? null, $post->excerpt ?? null]),
                'datePublished' => self::dateIso($post->published_at ?? $post->created_at ?? null),
                'dateModified' => self::dateIso($post->updated_at ?? $post->published_at ?? null),
                'mainEntityOfPage' => ['@id' => $canonical . '#webpage'],
                'author' => [
                    '@type' => 'Organization',
                    '@id' => self::LOCAL_BUSINESS_ID,
                    'name' => self::SITE_NAME,
                ],
                'publisher' => ['@id' => self::LOCAL_BUSINESS_ID],
            ],
            self::breadcrumbNode($canonical, [
                ['name' => 'Home', 'url' => self::SITE_URL . '/'],
                ['name' => 'Blog', 'url' => self::SITE_URL . '/blog/'],
                ['name' => $category->name ?? 'Article', 'url' => $category ? self::SITE_URL . '/blog/' . trim((string)$category->slug, '/') . '/' : $canonical],
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
                'isPartOf' => ['@id' => self::WEBSITE_ID],
                'about' => ['@id' => self::LOCAL_BUSINESS_ID],
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
                'provider' => ['@id' => self::LOCAL_BUSINESS_ID],
                'areaServed' => self::defaultAreaServed(false),
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
                'isPartOf' => ['@id' => self::WEBSITE_ID],
                'about' => ['@id' => self::LOCAL_BUSINESS_ID],
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
                'publisher' => ['@id' => self::LOCAL_BUSINESS_ID],
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

    public static function defaultSchema(string $canonical, array $seo = [], string $templateChild = ''): array
    {
        $canonical = self::absoluteUrl($canonical);
        $title = self::firstFilled([$seo['title'] ?? null, self::SITE_NAME]);
        $description = self::firstFilled([
            $seo['description'] ?? null,
            'VNV Events is a full-service event company in South Florida specializing in event planning, catering, decor, floral design, entertainment, rentals, media, staffing, and production.',
        ]);

        $graph = [
            self::organizationNode(),
            self::websiteNode(),
            self::webPageNode($canonical, $title, $description),
        ];

        if ($canonical !== self::SITE_URL . '/') {
            $graph[] = self::breadcrumbNode($canonical, self::breadcrumbsFromCanonical($canonical, $title));
        }

        if ($canonical !== self::SITE_URL . '/' && self::pathLooksLikeService(self::pathFromCanonical($canonical), $title . ' ' . $description . ' ' . $templateChild)) {
            $graph[] = self::serviceNode($canonical, $title, $description);
        }

        return self::schema($graph);
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
            '@type' => 'LocalBusiness',
            '@id' => self::LOCAL_BUSINESS_ID,
            'name' => self::SITE_NAME,
            'url' => self::SITE_URL . '/',
            'telephone' => '+13052045427',
            'email' => 'info@vnvevents.com',
            'priceRange' => '$$-$$$$',
            'additionalType' => [
                'https://schema.org/EventPlanner',
                'https://schema.org/FoodEstablishment',
                'https://schema.org/Florist',
                'https://schema.org/EntertainmentBusiness',
            ],
            'logo' => [
                '@type' => 'ImageObject',
                'url' => self::LOGO_URL,
            ],
            'image' => self::LOGO_URL,
            'description' => 'VNV Events LLC provides event planning, catering, decor, floral design, entertainment, rentals, photography, videography, event staffing, and event production services in South Florida.',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '10258 NW 47th St',
                'addressLocality' => 'Sunrise',
                'addressRegion' => 'FL',
                'postalCode' => '33351',
                'addressCountry' => 'US',
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'address' => '10258 NW 47th St, Sunrise, FL 33351',
            ],
            'areaServed' => self::defaultAreaServed(true),
            'openingHoursSpecification' => [
                [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    'opens' => '10:00',
                    'closes' => '17:00',
                ],
                [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'Saturday',
                    'opens' => '10:00',
                    'closes' => '14:00',
                ],
            ],
            'contactPoint' => [
                [
                    '@type' => 'ContactPoint',
                    'telephone' => '+13052045427',
                    'contactType' => 'customer service',
                    'areaServed' => 'US-FL',
                    'availableLanguage' => ['English', 'Spanish'],
                ],
            ],
            'makesOffer' => [
                ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => 'Event planning and coordination']],
                ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => 'Catering and chef-led food stations']],
                ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => 'Event rentals, decor and floral design']],
                ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => 'DJ, sound, lighting and event production']],
                ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => 'Photography, videography, photo booths and streaming']],
                ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => 'Bartending and event staffing']],
            ],
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => '5.0',
                'bestRating' => '5',
                'worstRating' => '1',
                'reviewCount' => '62',
                'ratingCount' => '62',
            ],
            'review' => [
                [
                    '@type' => 'Review',
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => '5',
                        'bestRating' => '5',
                        'worstRating' => '1',
                    ],
                    'author' => [
                        '@type' => 'Person',
                        'name' => 'Stacy S',
                    ],
                    'reviewBody' => 'Recently hosted a corporate event and Vivian was absolutely perfect to work with. Music, food, vibes and theme were immaculate.',
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => 'Google Business Profile',
                        'url' => self::GOOGLE_BUSINESS_PROFILE_URL,
                    ],
                ],
                [
                    '@type' => 'Review',
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => '5',
                        'bestRating' => '5',
                        'worstRating' => '1',
                    ],
                    'author' => [
                        '@type' => 'Person',
                        'name' => 'Christy R',
                    ],
                    'reviewBody' => 'Isabel was amazing to work with and knew exactly what I needed. The pasta station experience made the party more enjoyable and service was top notch.',
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => 'Google Business Profile',
                        'url' => self::GOOGLE_BUSINESS_PROFILE_URL,
                    ],
                ],
                [
                    '@type' => 'Review',
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => '5',
                        'bestRating' => '5',
                        'worstRating' => '1',
                    ],
                    'author' => [
                        '@type' => 'Person',
                        'name' => 'Nicole S',
                    ],
                    'reviewBody' => 'The team was incredible. They were attentive to detail and made sure everything was running smoothly.',
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => 'Google Business Profile',
                        'url' => self::GOOGLE_BUSINESS_PROFILE_URL,
                    ],
                ],
            ],
            'sameAs' => [
                'https://www.instagram.com/vnvevents/',
                self::GOOGLE_BUSINESS_PROFILE_URL,
            ],
        ];
    }

    private static function locationBusinessNode(string $canonical, object $page, string $areaName): array
    {
        $city = self::clean($page->city ?? '');
        $state = self::clean($page->state ?? '');
        $nameArea = $areaName !== '' ? $areaName : self::firstFilled([$city, $page->title ?? null, 'South Florida']);

        return [
            '@type' => 'LocalBusiness',
            '@id' => $canonical . '#localbusiness',
            'name' => 'VNV Events in ' . $nameArea,
            'url' => $canonical,
            'telephone' => '+13052045427',
            'email' => 'info@vnvevents.com',
            'description' => self::firstFilled([
                $page->meta_description ?? null,
                $page->excerpt ?? null,
                'VNV Events provides event planning, catering, rentals, decor, DJ, staffing and production services in ' . $nameArea . '.',
            ]),
            'parentOrganization' => ['@id' => self::LOCAL_BUSINESS_ID],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '10258 NW 47th St',
                'addressLocality' => 'Sunrise',
                'addressRegion' => 'FL',
                'postalCode' => '33351',
                'addressCountry' => 'US',
            ],
            'areaServed' => [[
                '@type' => $city !== '' ? 'City' : 'AdministrativeArea',
                'name' => $nameArea,
                'addressRegion' => $state ?: 'FL',
            ]],
            'priceRange' => '$$-$$$$',
            'image' => self::absoluteUrl(self::firstFilled([$page->hero_image ?? null, $page->og_image ?? null, self::LOGO_URL])),
            'sameAs' => [
                self::GOOGLE_BUSINESS_PROFILE_URL,
                'https://www.instagram.com/vnvevents/',
            ],
        ];
    }

    private static function websiteNode(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::WEBSITE_ID,
            'url' => self::SITE_URL . '/',
            'name' => self::SITE_NAME,
            'publisher' => ['@id' => self::LOCAL_BUSINESS_ID],
            'inLanguage' => 'en-US',
        ];
    }

    private static function webPageNode(string $canonical, string $title, string $description): array
    {
        $node = [
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $title,
            'description' => $description,
            'isPartOf' => ['@id' => self::WEBSITE_ID],
            'about' => ['@id' => self::LOCAL_BUSINESS_ID],
            'inLanguage' => 'en-US',
        ];

        if ($canonical !== self::SITE_URL . '/') {
            $node['breadcrumb'] = ['@id' => $canonical . '#breadcrumb'];
        }

        return $node;
    }

    private static function serviceNode(string $canonical, string $title, string $description, ?string $areaName = null): array
    {
        return [
            '@type' => 'Service',
            '@id' => $canonical . '#service',
            'name' => $title,
            'url' => $canonical,
            'description' => $description,
            'provider' => ['@id' => self::LOCAL_BUSINESS_ID],
            'areaServed' => $areaName
                ? [['@type' => 'Place', 'name' => $areaName]]
                : self::defaultAreaServed(false),
            'serviceType' => self::serviceTypeFromText($title),
        ];
    }

    private static function productOffersNode(object $product, string $canonical): array
    {
        $productType = strtoupper((string)($product->product_type ?? 'FIXED'));
        $availability = ((int)($product->stock_quantity ?? 0) > 0 || $productType === 'VARIABLE')
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';

        if ($productType === 'VARIABLE') {
            $lowPrice = self::moneyValue($product->min_price ?? $product->display_price ?? $product->price ?? 0);
            $highPrice = self::moneyValue($product->max_price ?? $product->display_price ?? $product->price ?? $lowPrice);
            $offers = [];

            foreach (($product->variations ?? []) as $variation) {
                $variationPrice = self::moneyValue($variation->effective_price ?? $variation->price ?? $lowPrice);
                if ((float)$variationPrice <= 0) {
                    continue;
                }

                $offers[] = [
                    '@type' => 'Offer',
                    'name' => self::clean($variation->name ?? ($product->name ?? 'Product option')),
                    'url' => $canonical,
                    'priceCurrency' => 'USD',
                    'price' => $variationPrice,
                    'availability' => 'https://schema.org/InStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => ['@id' => self::LOCAL_BUSINESS_ID],
                ];
            }

            return array_filter([
                '@type' => 'AggregateOffer',
                'url' => $canonical,
                'priceCurrency' => 'USD',
                'lowPrice' => $lowPrice,
                'highPrice' => $highPrice,
                'offerCount' => max(1, count($offers)),
                'availability' => $availability,
                'seller' => ['@id' => self::LOCAL_BUSINESS_ID],
                'offers' => $offers ?: null,
            ]);
        }

        return [
            '@type' => 'Offer',
            'url' => $canonical,
            'priceCurrency' => 'USD',
            'price' => self::moneyValue($product->display_price ?? $product->promo_price ?? $product->price ?? 0),
            'availability' => $availability,
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@id' => self::LOCAL_BUSINESS_ID],
        ];
    }

    private static function productCategoryName(object $product): string
    {
        $categories = $product->categories ?? [];
        if (is_array($categories) && !empty($categories)) {
            $category = reset($categories);
            return self::firstFilled([$category->name ?? null, 'VNV Events Services']);
        }

        return 'VNV Events Services';
    }

    private static function productCategoryUrl(object $product): string
    {
        $categories = $product->categories ?? [];
        if (is_array($categories) && !empty($categories)) {
            $category = reset($categories);
            $slug = trim((string)($category->slug ?? ''), '/');
            if ($slug !== '') {
                return self::SITE_URL . '/product-category/' . $slug . '/';
            }
        }

        return self::SITE_URL . '/store-categories/';
    }

    private static function moneyValue($value): string
    {
        $amount = max(0, (float)$value);
        return number_format($amount, 2, '.', '');
    }

    private static function breadcrumbNode(string $canonical, array $items): array
    {
        $elements = [];
        $seen = [];
        foreach (array_values($items) as $index => $item) {
            $name = self::clean($item['name'] ?? '');
            $url = self::absoluteUrl($item['url'] ?? $canonical);
            if ($name === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $elements[] = [
                '@type' => 'ListItem',
                'position' => count($elements) + 1,
                'name' => $name,
                'item' => $url,
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
        foreach (self::serviceNeedles() as $needle) {
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
        $stored = self::validCanonical($stored);
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
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            return self::SITE_URL . self::normalizePath($path);
        }

        return self::SITE_URL . self::normalizePath($url);
    }

    private static function validCanonical(?string $url): ?string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url)) {
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
            if (!in_array($host, ['vnvevents.com', 'www.vnvevents.com'], true)) {
                return null;
            }
        }

        return $url;
    }

    private static function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/') . '/';
        }

        return $path;
    }

    private static function pathFromCanonical(string $canonical): string
    {
        return self::normalizePath(parse_url($canonical, PHP_URL_PATH) ?: '/');
    }

    private static function breadcrumbsFromCanonical(string $canonical, string $title): array
    {
        $path = trim(self::pathFromCanonical($canonical), '/');
        $items = [['name' => 'Home', 'url' => self::SITE_URL . '/']];

        if ($path === '') {
            return $items;
        }

        $segments = explode('/', $path);
        $running = '';
        foreach ($segments as $index => $segment) {
            $running .= '/' . $segment;
            $isLast = $index === count($segments) - 1;
            $items[] = [
                'name' => $isLast ? $title : self::titleFromSlug($segment),
                'url' => self::SITE_URL . self::normalizePath($running),
            ];
        }

        return $items;
    }

    private static function titleFromSlug(string $slug): string
    {
        $special = [
            'blog' => 'Blog',
            'locations' => 'Locations',
            'vnv-gourmet' => 'VNV Gourmet',
            'catering-stations-south-florida' => 'Live Catering Stations',
            'product' => 'Products',
            'product-category' => 'Product Categories',
            'services' => 'Services',
        ];

        return $special[$slug] ?? ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    private static function pathLooksLikeService(string $path, string $text): bool
    {
        $haystack = strtolower($path . ' ' . $text);
        if (str_contains($haystack, '/blog/')) {
            return false;
        }

        foreach (self::serviceNeedles() as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function serviceNeedles(): array
    {
        return [
            'service',
            'event',
            'wedding',
            'corporate',
            'production',
            'catering',
            'rental',
            'photo',
            'photography',
            'videography',
            'video',
            'dj',
            'floral',
            'decor',
            'bartending',
            'staffing',
            'quince',
            'baby shower',
            'private party',
            'vnv-gourmet',
            'pasta station',
            'paella',
            'tapas',
            'pizza station',
            'taco station',
            'brunch station',
            'crepes',
            'sushi boat',
            'dessert station',
            'appetizer',
        ];
    }

    private static function serviceTypeFromText(string $text): string
    {
        $text = strtolower($text);
        foreach ([
            'wedding' => 'Wedding planning',
            'catering' => 'Event catering',
            'gourmet' => 'Event catering',
            'dj' => 'DJ services',
            'photo' => 'Photography and videography',
            'video' => 'Photography and videography',
            'floral' => 'Floral design',
            'decor' => 'Event decor',
            'rental' => 'Event rentals',
            'staff' => 'Event staffing',
            'corporate' => 'Corporate events',
            'bartending' => 'Bartending',
        ] as $needle => $type) {
            if (str_contains($text, $needle)) {
                return $type;
            }
        }

        return 'Event services';
    }

    private static function defaultAreaServed(bool $includeSouthFlorida): array
    {
        $areas = [
            ['@type' => 'AdministrativeArea', 'name' => 'Miami-Dade County'],
            ['@type' => 'AdministrativeArea', 'name' => 'Broward County'],
            ['@type' => 'AdministrativeArea', 'name' => 'Palm Beach County'],
        ];

        if ($includeSouthFlorida) {
            $areas[] = ['@type' => 'Place', 'name' => 'South Florida'];
        }

        return $areas;
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
