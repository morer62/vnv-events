<?php

namespace App\Services;

use Exception;

class LocationPageAIService
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4o-mini';
    private const DEFAULT_INFO_ITEMS = [
        ['label' => 'Phone', 'value' => '+1 305-204-5427'],
        ['label' => 'Email', 'value' => 'info@vnvevents.com'],
        ['label' => 'Hours', 'value' => 'Mon - Fri 10 AM - 5 PM'],
        ['label' => 'Saturday', 'value' => '10 AM - 2 PM'],
        ['label' => 'Sunday', 'value' => 'Closed'],
        ['label' => 'Address', 'value' => '10258 NW 47th St, Sunrise, FL 33351'],
    ];
    private const DEFAULT_GALLERY_IMAGES = [
        'https://images.unsplash.com/photo-1587776215053-d991fe770b4e?q=80&w=764&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
        'https://plus.unsplash.com/premium_photo-1711305772609-a59b3f90f629?q=80&w=687&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
        'https://images.unsplash.com/photo-1651065698373-f310e3b99326?q=80&w=1470&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
    ];
    private const DEFAULT_TESTIMONIALS = [
        ['quote' => 'VNV Events made our wedding weekend look and feel like a luxury production.', 'name' => 'Sofia M.', 'role' => 'Bride'],
        ['quote' => 'From timeline to guest experience, every detail felt intentional and elegant.', 'name' => 'Carla R.', 'role' => 'Corporate Client'],
        ['quote' => 'Professional team, impeccable execution, and a truly glamorous setup.', 'name' => 'Alyssa D.', 'role' => 'Private Event Host'],
        ['quote' => 'Their team transformed our venue into a refined, unforgettable experience for guests.', 'name' => 'Mariana L.', 'role' => 'Social Event Host'],
        ['quote' => 'Planning felt effortless. Every milestone was organized, elegant and right on time.', 'name' => 'Daniel K.', 'role' => 'Groom'],
        ['quote' => 'Exceptional communication and flawless production from concept to final toast.', 'name' => 'Vanessa T.', 'role' => 'Brand Manager'],
        ['quote' => 'The decor direction and production quality exceeded every expectation we had.', 'name' => 'Isabella N.', 'role' => 'Bride'],
        ['quote' => 'Our executive event looked world-class and ran smoothly from start to finish.', 'name' => 'Michael R.', 'role' => 'Operations Director'],
    ];

    /**
     * @throws Exception
     */
    public static function generate(array $input): array
    {
        $apiKey = trim((string)($_ENV['OPENAI_TOKEN'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('OPENAI_TOKEN is not configured.');
        }

        $promptPath = __DIR__ . '/../Config/location_page_ai_prompt.txt';
        $basePrompt = is_file($promptPath) ? (string)file_get_contents($promptPath) : '';
        if (trim($basePrompt) === '') {
            throw new Exception('Location AI prompt file is missing or empty.');
        }

        $location = trim((string)($input['location'] ?? ''));
        $slug = trim((string)($input['slug'] ?? ''));
        $templateKey = trim((string)($input['template_key'] ?? 'location-home-luxe'));
        $existingBlocks = $input['dynamic_blocks'] ?? [];
        if (!is_array($existingBlocks)) {
            $existingBlocks = [];
        }

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0.9,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $basePrompt
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'Create a complete Florida location page payload for the CMS form.',
                        'input' => [
                            'location' => $location,
                            'slug' => $slug,
                            'template_key' => $templateKey,
                            'existing_dynamic_blocks' => $existingBlocks,
                        ],
                        'required_output_shape' => [
                            'title' => 'string',
                            'slug' => 'string',
                            'category' => 'location',
                            'city' => 'string',
                            'county' => 'string',
                            'state' => 'Florida',
                            'hero_title' => 'string',
                            'hero_subtitle' => 'string',
                            'excerpt' => 'string',
                            'content_long' => 'html string',
                            'primary_keyword' => 'string',
                            'secondary_keywords' => 'comma separated string',
                            'hero_image' => 'string',
                            'meta_title' => 'string',
                            'meta_keywords' => 'string',
                            'meta_description' => 'string',
                            'og_title' => 'string',
                            'og_image' => 'string',
                            'og_description' => 'string',
                            'canonical_url' => 'string',
                            'custom_css' => 'string',
                            'faq_json' => [['question' => 'string', 'answer' => 'string']],
                            'dynamic_blocks_json' => 'array',
                            'schema_json' => 'object'
                        ],
                        'notes' => [
                            'If location appears to be in Broward County, set county to Broward County.',
                            'If location appears to be in Miami-Dade, set county to Miami-Dade County.',
                            'If location appears to be in Palm Beach, set county to Palm Beach County.',
                            'Keep custom style intent glamorous, elegant, and mobile-friendly through copy suggestions.'
                        ]
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]
            ],
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new Exception('AI request failed: ' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('AI request failed with status ' . $httpCode . '.');
        }

        $decoded = json_decode($response, true);
        $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw new Exception('Invalid AI JSON response.');
        }

        return self::normalizePayload($json, $location, $slug);
    }

    private static function normalizePayload(array $raw, string $location, string $slug): array
    {
        $safeSlug = strtolower(trim($slug)) !== '' ? strtolower(trim($slug)) : strtolower(trim((string)($raw['slug'] ?? '')));
        $safeSlug = preg_replace('/[^a-z0-9\-]/', '-', $safeSlug);
        $safeSlug = preg_replace('/-+/', '-', (string)$safeSlug);
        $safeSlug = trim((string)$safeSlug, '-');

        $faq = $raw['faq_json'] ?? [];
        if (!is_array($faq)) {
            $faq = [];
        }

        $city = trim((string)($raw['city'] ?? $location));
        $county = trim((string)($raw['county'] ?? ''));
        $state = trim((string)($raw['state'] ?? 'Florida')) ?: 'Florida';

        $blocks = $raw['dynamic_blocks_json'] ?? [];
        if (!is_array($blocks)) {
            $blocks = [];
        }
        $blocks = self::normalizeDynamicBlocks($blocks, $city, $county, $state);

        $schema = $raw['schema_json'] ?? new \stdClass();
        if (!is_array($schema) && !is_object($schema)) {
            $schema = new \stdClass();
        }

        $title = trim((string)($raw['title'] ?? ''));
        if ($title === '') {
            $title = self::buildDistinctSeoTitle($city, $state, $safeSlug !== '' ? $safeSlug : $location);
        }

        $heroTitle = trim((string)($raw['hero_title'] ?? ''));
        if ($heroTitle === '') {
            $heroTitle = self::buildDistinctHeroTitle($city, $state, $safeSlug !== '' ? $safeSlug : $location);
        }

        $heroSubtitle = trim((string)($raw['hero_subtitle'] ?? ''));
        if ($heroSubtitle === '') {
            $heroSubtitle = self::buildDistinctHeroSubtitle($city, $safeSlug !== '' ? $safeSlug : $location);
        }

        return [
            'title' => $title,
            'slug' => $safeSlug,
            'category' => 'location',
            'city' => $city,
            'county' => $county,
            'state' => $state,
            'hero_title' => $heroTitle,
            'hero_subtitle' => $heroSubtitle,
            'excerpt' => trim((string)($raw['excerpt'] ?? '')) ?: ('Luxury wedding, social and corporate event planning in ' . ($city !== '' ? $city : 'South Florida') . ', ' . $state . '.'),
            'content_long' => trim((string)($raw['content_long'] ?? '')) ?: self::buildDefaultMainContent($city, $state),
            'primary_keyword' => trim((string)($raw['primary_keyword'] ?? '')),
            'secondary_keywords' => trim((string)($raw['secondary_keywords'] ?? '')),
            'hero_image' => trim((string)($raw['hero_image'] ?? '')),
            'meta_title' => trim((string)($raw['meta_title'] ?? '')),
            'meta_keywords' => trim((string)($raw['meta_keywords'] ?? '')),
            'meta_description' => trim((string)($raw['meta_description'] ?? '')),
            'og_title' => trim((string)($raw['og_title'] ?? '')),
            'og_image' => trim((string)($raw['og_image'] ?? '')),
            'og_description' => trim((string)($raw['og_description'] ?? '')),
            'canonical_url' => trim((string)($raw['canonical_url'] ?? ('https://vnvevents.com/' . $safeSlug))),
            'custom_css' => trim((string)($raw['custom_css'] ?? '')),
            'faq_json' => array_values($faq),
            'dynamic_blocks_json' => array_values($blocks),
            'schema_json' => $schema,
        ];
    }

    private static function normalizeDynamicBlocks(array $blocks, string $city, string $county, string $state): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = trim((string)($block['type'] ?? 'text'));
            $type = $type !== '' ? $type : 'text';
            $block['type'] = $type;
            $block['title'] = trim((string)($block['title'] ?? ''));
            $block['content'] = trim((string)($block['content'] ?? ''));

            if ($type === 'info') {
                $items = $block['items'] ?? [];
                if (!is_array($items) || count($items) < 6) {
                    $items = self::DEFAULT_INFO_ITEMS;
                }
                $block['items'] = array_values($items);
            }

            if ($type === 'text') {
                if ($block['title'] === '') {
                    $block['title'] = 'Who We Are';
                }
                if ($block['content'] === '') {
                    $block['content'] = self::buildDefaultWhoWeAreContent($city, $county, $state);
                }
            }

            if ($type === 'images') {
                if ($block['title'] === '') {
                    $block['title'] = 'Unforgettable Moments';
                }
                $images = $block['images'] ?? [];
                if (!is_array($images)) {
                    $images = [];
                }
                if (count($images) === 0) {
                    $images = self::buildDefaultGalleryImages($city, $state);
                }
                $block['images'] = array_values($images);
                $block['columns'] = max(1, min(4, (int)($block['columns'] ?? 3)));
            }

            if ($type === 'links') {
                if ($block['title'] === '') {
                    $block['title'] = 'Explore More';
                }
                $links = $block['links'] ?? [];
                if (!is_array($links)) {
                    $links = [];
                }
                $block['links'] = array_values($links);
            }

            if ($type === 'testimonials') {
                $items = $block['items'] ?? [];
                if (!is_array($items)) {
                    $items = [];
                }
                if (count($items) < 8) $items = self::DEFAULT_TESTIMONIALS;
                $block['items'] = array_values($items);
                $block['enabled'] = !isset($block['enabled']) || (bool)$block['enabled'];
            }

            if ($type === 'map') {
                $address = trim((string)($block['address'] ?? ''));
                if ($block['title'] === '') {
                    $block['title'] = 'Our Location';
                }
                if ($address === '') {
                    $parts = array_filter([$city, $county, $state]);
                    $address = implode(', ', $parts);
                }
                $block['address'] = $address;

                $lat = isset($block['lat']) ? (float)$block['lat'] : 0.0;
                $lng = isset($block['lng']) ? (float)$block['lng'] : 0.0;
                if (!$lat || !$lng) {
                    $coords = self::geocodeAddress($address);
                    if ($coords !== null) {
                        $block['lat'] = $coords['lat'];
                        $block['lng'] = $coords['lng'];
                    }
                }
            }

            if (self::isMeaningfulBlock($block)) {
                $normalized[] = $block;
            }
        }

        if (!self::hasBlockType($normalized, 'info')) {
            $normalized[] = [
                'type' => 'info',
                'title' => 'Quick Information',
                'items' => self::DEFAULT_INFO_ITEMS
            ];
        }
        if (!self::hasBlockType($normalized, 'images')) {
            $normalized[] = [
                'type' => 'images',
                'title' => 'Unforgettable Moments',
                'columns' => 3,
                'images' => self::buildDefaultGalleryImages($city, $state),
            ];
        }
        if (!self::hasBlockType($normalized, 'testimonials')) {
            $normalized[] = [
                'type' => 'testimonials',
                'title' => 'What Clients Say',
                'enabled' => true,
                'items' => self::DEFAULT_TESTIMONIALS
            ];
        }
        if (!self::hasBlockType($normalized, 'map')) {
            $fallbackAddress = implode(', ', array_filter([$city, $county, $state]));
            $map = [
                'type' => 'map',
                'title' => 'Find Us in ' . ($city !== '' ? $city : 'South Florida'),
                'address' => $fallbackAddress
            ];
            $coords = self::geocodeAddress($fallbackAddress);
            if ($coords !== null) {
                $map['lat'] = $coords['lat'];
                $map['lng'] = $coords['lng'];
            }
            $normalized[] = $map;
        }

        return array_values($normalized);
    }

    private static function isMeaningfulBlock(array $block): bool
    {
        $type = (string)($block['type'] ?? 'text');

        if (in_array($type, ['info', 'testimonials', 'map'], true)) {
            return true;
        }
        if ($type === 'text') {
            return trim((string)($block['title'] ?? '')) !== '' || trim((string)($block['content'] ?? '')) !== '';
        }
        if ($type === 'images') {
            $images = $block['images'] ?? [];
            return is_array($images) && count($images) > 0;
        }
        if ($type === 'image') {
            return trim((string)($block['image_url'] ?? '')) !== '';
        }
        if ($type === 'links') {
            $links = $block['links'] ?? [];
            return is_array($links) && count($links) > 0;
        }

        return trim((string)($block['title'] ?? '')) !== '' || trim((string)($block['content'] ?? '')) !== '';
    }

    private static function buildDefaultMainContent(string $city, string $state): string
    {
        $cityText = $city !== '' ? $city : 'South Florida';
        return '<h2>We Are Ready to Craft Your Next Unforgettable Experience</h2>'
            . '<p>At VNV Events, we design elevated celebrations in ' . htmlspecialchars($cityText, ENT_QUOTES) . ', ' . htmlspecialchars($state, ENT_QUOTES) . ' with premium planning, design direction and flawless execution.</p>'
            . '<h3>What We Offer in This Location</h3>'
            . '<ul>'
            . '<li>Full Event Planning and Coordination</li>'
            . '<li>Luxury Wedding Planning</li>'
            . '<li>Corporate Event Production</li>'
            . '<li>Decor, Floral Design, and Styling</li>'
            . '<li>DJ, Entertainment, and Multimedia Services</li>'
            . '</ul>';
    }

    private static function buildDefaultWhoWeAreContent(string $city, string $county, string $state): string
    {
        $parts = array_filter([$city, $county, $state]);
        $loc = implode(', ', $parts);
        if ($loc === '') {
            $loc = 'South Florida';
        }
        return '<p>At VNV Events, we believe every event should feel timeless and intentional. Our team creates high-end social and corporate experiences in ' . htmlspecialchars($loc, ENT_QUOTES) . ' with elegant styling, meticulous coordination and white-glove service.</p>';
    }

    private static function buildDefaultGalleryImages(string $city, string $state): array
    {
        $altBase = trim($city . ', ' . $state);
        if ($altBase === ',' || $altBase === '') {
            $altBase = 'South Florida';
        }

        return [
            ['url' => self::DEFAULT_GALLERY_IMAGES[0], 'alt' => 'Luxury event decor in ' . $altBase],
            ['url' => self::DEFAULT_GALLERY_IMAGES[1], 'alt' => 'Elegant wedding setup in ' . $altBase],
            ['url' => self::DEFAULT_GALLERY_IMAGES[2], 'alt' => 'Premium event atmosphere in ' . $altBase],
        ];
    }

    private static function hasBlockType(array $blocks, string $type): bool
    {
        foreach ($blocks as $block) {
            if ((string)($block['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    private static function geocodeAddress(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $apiKey = trim((string)($_ENV['GOOGLE_MAPS_API_KEY'] ?? $_ENV['GOOGLE_KEY'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode($address) . '&key=' . rawurlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode($response, true);
        $result = $decoded['results'][0]['geometry']['location'] ?? null;
        if (!is_array($result) || !isset($result['lat'], $result['lng'])) {
            return null;
        }

        return [
            'lat' => (float)$result['lat'],
            'lng' => (float)$result['lng'],
        ];
    }

    private static function buildDistinctSeoTitle(string $city, string $state, string $seed): string
    {
        $cityLabel = $city !== '' ? $city : 'South Florida';
        $stateLabel = $state !== '' ? $state : 'Florida';
        $variants = [
            'High-level Event Planner Agency in %s, %s',
            'Premier Wedding & Corporate Event Agency in %s, %s',
            'Boutique Luxury Event Planning in %s, %s',
            '%s, %s Event Planning for Elevated Celebrations',
        ];
        $idx = self::seedIndex($seed . '|seo_title', count($variants));
        return sprintf($variants[$idx], $cityLabel, $stateLabel);
    }

    private static function buildDistinctHeroTitle(string $city, string $state, string $seed): string
    {
        $cityLabel = $city !== '' ? $city : 'South Florida';
        $stateLabel = $state !== '' ? $state : 'Florida';
        $variants = [
            'High-level Event Planner Agency in %s, %s',
            'Premier Event Design & Planning Team in %s, %s',
            'Luxury Event Production Agency in %s, %s',
            'Elevated Celebrations Curated in %s, %s',
        ];
        $idx = self::seedIndex($seed . '|hero_title', count($variants));
        return sprintf($variants[$idx], $cityLabel, $stateLabel);
    }

    private static function buildDistinctHeroSubtitle(string $city, string $seed): string
    {
        $cityLabel = $city !== '' ? $city : 'South Florida';
        $variants = [
            'The event you imagine, brought to life with refined planning and flawless execution.',
            'From concept to final toast, we craft polished experiences your guests will remember.',
            'Elegant design, seamless coordination, and white-glove service for unforgettable celebrations.',
            'Sophisticated planning and production tailored for weddings, socials, and corporate events.',
            'We transform your vision into an elevated experience with style, precision, and warmth.',
        ];
        $idx = self::seedIndex($seed . '|hero_subtitle|' . $cityLabel, count($variants));
        return $variants[$idx];
    }

    private static function seedIndex(string $seed, int $max): int
    {
        if ($max <= 1) {
            return 0;
        }
        $hash = crc32(strtolower(trim($seed)));
        if ($hash < 0) {
            $hash = -$hash;
        }
        return $hash % $max;
    }
}
