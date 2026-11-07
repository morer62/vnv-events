<?php

namespace App\Services;

use App\Repositories\Connection;
use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use Throwable;

final class CateringLandingProductService
{
    private const AREA_SERVED = [
        ['@type' => 'AdministrativeArea', 'name' => 'Broward County'],
        ['@type' => 'AdministrativeArea', 'name' => 'Miami-Dade County'],
        ['@type' => 'AdministrativeArea', 'name' => 'Palm Beach County'],
    ];

    private const SERVICES = [
        'pasta' => [
            'code' => 'PASTA', 'name' => 'Pasta Station',
            'landing_slug' => 'pasta-station-catering-south-florida',
            'product_slug' => 'pasta-station', 'fallback_price' => 19.00,
            'minimum_guests' => 25, 'base_variations' => ['Pasta Station'],
            'image' => 'assets/images/service_photos/pasta_station_1.webp',
            'summary' => 'Three pastas, three sauces and two proteins in a flexible live station.',
        ],
        'appetizer' => [
            'code' => 'APP', 'name' => 'Appetizer Station',
            'landing_slug' => 'appetizer-catering-south-florida',
            'product_slug' => 'hors-doeuvres-21-77-per-guest-min-25',
            'fallback_price' => 21.77, 'minimum_guests' => 25,
            'base_variations' => [
                'THE SIGNATURE COLLECTION (Classic Variety)',
                'THE OCEAN BLUE COLLECTION (Sushi Experience)',
            ],
            'image' => 'assets/images/service_photos/appetizers_lite.webp',
            'summary' => 'Seven appetizer pieces per guest with classic or sushi-forward collections.',
        ],
        'crepes' => [
            'code' => 'CREPES', 'name' => 'Crepes Station',
            'landing_slug' => 'crepes-catering-south-florida',
            'product_slug' => 'crepes-station-1999-per-guest-25-guest-minimum-buffet-style',
            'fallback_price' => 19.00, 'minimum_guests' => 25,
            'base_variations' => [],
            'image' => 'assets/images/service_photos/crepes_station_1_lite.webp',
            'summary' => 'Sweet and savory crepes prepared fresh in a guest-facing station.',
        ],
        'taco' => [
            'code' => 'TACO', 'name' => 'Taco Station',
            'landing_slug' => 'taco-station-catering-south-florida',
            'product_slug' => 'taco-station', 'fallback_price' => 19.00,
            'minimum_guests' => 25, 'base_variations' => ['Taco Station'],
            'image' => 'assets/images/service_photos/tacos.webp',
            'summary' => 'A fast, colorful taco and burrito bar with two proteins.',
        ],
        'corporate' => [
            'code' => 'CORP', 'name' => 'Corporate Catering',
            'landing_slug' => 'corporate-catering-south-florida',
            'product_slug' => 'corporate-team-appreciation-catering-22-5-p-p-min-50',
            'fallback_price' => 17.00, 'minimum_guests' => 50,
            'base_variations' => ['Food Station'],
            'image' => 'assets/images/service_photos/brunch_tation_lite.webp',
            'summary' => 'Staffed stations for company events; meal boxes are a separate format.',
        ],
        'pizza' => [
            'code' => 'PIZZA', 'name' => 'Pizza Station',
            'landing_slug' => 'pizza-station-catering-south-florida',
            'product_slug' => 'pizza-station', 'fallback_price' => 19.00,
            'minimum_guests' => 25, 'base_variations' => [],
            'image' => 'assets/images/service_photos/pizza_station_lite.webp',
            'summary' => 'Fresh pizza prepared on-site with selected crusts, sauces and toppings.',
        ],
        'paella' => [
            'code' => 'PAELLA', 'name' => 'Paella Station',
            'landing_slug' => 'paella-catering-south-florida',
            'product_slug' => 'paella-tapas-station-from-27-77-per-guest-min-25',
            'fallback_price' => 19.00, 'minimum_guests' => 25,
            'base_variations' => [],
            'image' => 'assets/images/service_photos/paella_station_lite.webp',
            'summary' => 'Live paella preparation with seafood, chicken or vegetarian directions.',
        ],
        'shawarma' => [
            'code' => 'SHAWARMA', 'name' => 'Shawarma Station',
            'landing_slug' => 'shawarma-station-catering-south-florida',
            'product_slug' => 'shawarma-station', 'fallback_price' => 19.00,
            'minimum_guests' => 25, 'base_variations' => [],
            'image' => 'assets/images/services/shawarma-station.webp',
            'summary' => 'Freshly carved shawarma with Mediterranean toppings, sides and sauces.',
        ],
    ];

    public static function hydrate(array $page): array
    {
        $serviceKey = self::serviceKeyForPage($page);
        $config = self::SERVICES[$serviceKey] ?? null;
        if ($config === null) {
            return $page;
        }

        $page['service_key'] = $serviceKey;
        $page['service_code'] = $config['code'];
        $page['service_name'] = $config['name'];
        $page['product_slug'] = $config['product_slug'];
        $page['product_url'] = 'https://vnvevents.com/product/' . $config['product_slug'] . '/';
        $page['canonical_url'] = 'https://vnvevents.com/' . $config['landing_slug'] . '/';
        $page['minimum_guests'] = (int)$config['minimum_guests'];
        $page['minimum'] = $serviceKey === 'corporate'
            ? '50-guest minimum for food stations'
            : $config['minimum_guests'] . '-guest minimum';
        $page['store_price_value'] = (float)$config['fallback_price'];
        $page['store_price_label'] = self::priceLabel((float)$config['fallback_price']);
        $page['store_price_updated'] = false;
        $tenantSettings = self::tenantSettings();
        $page['whatsapp_number'] = $tenantSettings['whatsapp_number'];
        $page['phone_number'] = '+13052045427';
        $page['ads_mode'] = self::isAdsMode();
        $page['paid_attribution'] = self::hasPaidAttribution();
        $page['ref_code'] = self::refCode($config['code']);
        $page['minimum_event_date'] = GourmetLandingAttributionService::minimumEventDate();
        $page['is_test'] = (string)($_GET['vnv_test'] ?? '') === '1';
        $page['landing_notice'] = self::landingNotice();

        try {
            $repository = new StoreProductsRepository();
            $product = $repository->getPublicBySlug(
                $config['product_slug'],
                AvomealContext::ownerId(),
                SiteContext::siteKey()
            );
            if ($product) {
                $details = $repository->getFullPublicProductDetails(
                    (int)$product->id,
                    AvomealContext::ownerId(),
                    SiteContext::siteKey()
                );
                $price = self::resolveBasePrice($product, (array)($details->variations ?? []), $config);
                if ($price > 0) {
                    $page['store_price_value'] = $price;
                    $page['store_price_label'] = self::priceLabel($price);
                    $page['store_price_updated'] = true;
                }
                $page['store_product_name'] = (string)($product->name ?? $config['name']);
            }
        } catch (Throwable $e) {
            error_log('Catering landing live price fallback for ' . $config['product_slug'] . ': ' . $e->getMessage());
        }

        if ($serviceKey === 'corporate') {
            $page['store_price_label'] = 'Stations from ' . self::money($page['store_price_value']);
            $mealBoxPrice = (float)($tenantSettings['corporate_meal_box_price'] ?? 0);
            $page['meal_box_price_value'] = $mealBoxPrice > 0 ? $mealBoxPrice : null;
            $page['meal_box_price_label'] = $mealBoxPrice > 0
                ? 'Meal boxes from ' . self::money($mealBoxPrice)
                : null;
        }

        $page = self::applyDynamicPriceCopy($page, $serviceKey);
        $page['whatsapp_message'] = self::buildWhatsappMessage($page);
        $page['schema'] = self::schema($page);
        return $page;
    }

    public static function viewData(array $page): array
    {
        $page = self::hydrate($page);
        $heroImage = ltrim((string)($page['hero_image'] ?? ''), '/');

        return [
            'page' => $page,
            'seo' => [
                'title' => (string)($page['meta_title'] ?? $page['service_name'] ?? 'VNV Gourmet'),
                'description' => (string)($page['meta_description'] ?? ''),
                'canonical' => (string)($page['canonical_url'] ?? 'https://vnvevents.com/vnv-gourmet/'),
                'og_type' => 'website',
                'og_image' => $heroImage !== '' ? 'https://vnvevents.com/' . $heroImage : '',
                'og_image_alt' => (string)($page['image_alt'] ?? $page['service_name'] ?? 'VNV Gourmet catering'),
                'site_name' => 'VNV Gourmet by VNV Events',
            ],
        ];
    }

    public static function catalog(): array
    {
        $catalog = [];
        foreach (['pasta', 'appetizer', 'crepes', 'taco'] as $key) {
            $config = self::SERVICES[$key];
            $page = self::hydrate(['slug' => $config['landing_slug'], 'faqs' => []]);
            $catalog[] = [
                'key' => $key, 'name' => $config['name'],
                'price' => $page['store_price_label'],
                'price_value' => (float)$page['store_price_value'],
                'minimum_guests' => $config['minimum_guests'],
                'minimum' => $page['minimum'], 'image' => $config['image'],
                'summary' => $config['summary'],
                'landing_url' => '/' . $config['landing_slug'] . '/',
                'product_url' => $page['product_url'],
            ];
        }
        return $catalog;
    }

    public static function corporateOverview(): array
    {
        $config = self::SERVICES['corporate'];
        $page = self::hydrate(['service_key' => 'corporate', 'slug' => $config['landing_slug'], 'faqs' => []]);

        return [
            'station_price' => $page['store_price_label'],
            'minimum' => $page['minimum'],
            'meal_box_price' => $page['meal_box_price_label'],
            'landing_url' => '/' . $config['landing_slug'] . '/',
        ];
    }

    public static function adsMode(): bool
    {
        return self::isAdsMode();
    }

    public static function comparisonContext(): array
    {
        $settings = self::tenantSettings();
        return [
            'ads_mode' => self::isAdsMode(),
            'paid_attribution' => self::hasPaidAttribution(),
            'ref_code' => self::refCode('STATIONS'),
            'phone_number' => '+13052045427',
            'whatsapp_number' => $settings['whatsapp_number'],
            'minimum_event_date' => GourmetLandingAttributionService::minimumEventDate(),
            'is_test' => (string)($_GET['vnv_test'] ?? '') === '1',
            'landing_notice' => self::landingNotice(),
        ];
    }

    public static function comparisonSchema(array $catalog): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [[
                '@type' => 'Service',
                'name' => 'Live Catering Stations in South Florida',
                'url' => 'https://vnvevents.com/catering-stations-south-florida/',
                'provider' => ['@type' => 'LocalBusiness', 'name' => 'VNV Events', 'telephone' => '+1-305-204-5427'],
                'areaServed' => self::AREA_SERVED,
                'serviceType' => array_values(array_map(fn(array $item): string => $item['name'], $catalog)),
                'offers' => array_values(array_map(function (array $item): array {
                    preg_match('/\$([0-9]+(?:\.[0-9]+)?)/', $item['price'], $match);
                    return [
                        '@type' => 'Offer',
                        'name' => $item['name'],
                        'price' => $match[1] ?? '',
                        'priceCurrency' => 'USD',
                        'url' => 'https://vnvevents.com' . $item['landing_url'],
                        'eligibleQuantity' => [
                            '@type' => 'QuantitativeValue',
                            'minValue' => (int)$item['minimum_guests'],
                            'unitText' => 'guest',
                        ],
                    ];
                }, $catalog)),
            ], [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://vnvevents.com/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'VNV Gourmet', 'item' => 'https://vnvevents.com/vnv-gourmet/'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Catering Stations', 'item' => 'https://vnvevents.com/catering-stations-south-florida/'],
                ],
            ]],
        ];
    }

    public static function resolveBasePrice(object $product, array $variations, array $config): float
    {
        $fixedPrice = (float)($product->display_price ?? $product->effective_price ?? $product->price ?? 0);
        if (strtoupper((string)($product->product_type ?? 'FIXED')) !== 'VARIABLE') {
            return $fixedPrice > 0 ? $fixedPrice : (float)$config['fallback_price'];
        }
        $wanted = array_map(fn(string $name): string => self::normalizeName($name), (array)($config['base_variations'] ?? []));
        $prices = [];
        foreach ($variations as $variation) {
            $name = self::normalizeName((string)($variation->name ?? ''));
            if ($name === '' || !in_array($name, $wanted, true)) {
                continue;
            }
            $price = (float)($variation->effective_price ?? $variation->promo_price ?? $variation->price ?? 0);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        return $prices !== [] ? min($prices) : (float)$config['fallback_price'];
    }

    public static function buildWhatsappMessage(array $page, array $values = []): string
    {
        return implode("\n", [
            'Hi VNV Events, I am interested in ' . ($page['service_name'] ?? 'VNV Gourmet') . '.',
            'Visible price: ' . ($page['store_price_label'] ?? 'Please confirm'),
            'Event date: ' . ($values['event_date'] ?? 'Not provided'),
            'Guests: ' . ($values['guest_count'] ?? 'Not provided'),
            'ZIP: ' . ($values['zip'] ?? 'Not provided'),
            'Reference: ' . ($page['ref_code'] ?? ''),
        ]);
    }

    private static function serviceKeyForPage(array $page): string
    {
        if (!empty($page['service_key']) && isset(self::SERVICES[$page['service_key']])) {
            return (string)$page['service_key'];
        }
        $slug = trim((string)($page['slug'] ?? ''));
        foreach (self::SERVICES as $key => $config) {
            if ($slug === $config['landing_slug']) {
                return $key;
            }
        }
        return '';
    }

    private static function schema(array $page): array
    {
        $graph = [[
            '@type' => 'Service', 'name' => $page['service_name'],
            'serviceType' => $page['eyebrow'] ?? $page['service_name'],
            'url' => $page['canonical_url'],
            'provider' => ['@type' => 'LocalBusiness', 'name' => 'VNV Events', 'telephone' => '+1-305-204-5427', 'url' => 'https://vnvevents.com/'],
            'areaServed' => self::AREA_SERVED,
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float)$page['store_price_value'], 2, '.', ''),
                'priceCurrency' => 'USD', 'url' => $page['product_url'],
                'eligibleQuantity' => ['@type' => 'QuantitativeValue', 'minValue' => (int)$page['minimum_guests'], 'unitText' => 'guest'],
            ],
        ], [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://vnvevents.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'VNV Gourmet', 'item' => 'https://vnvevents.com/vnv-gourmet/'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $page['service_name'], 'item' => $page['canonical_url']],
            ],
        ]];
        if (!empty($page['faqs'])) {
            $graph[] = [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn(array $faq): array => [
                    '@type' => 'Question', 'name' => $faq['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
                ], $page['faqs']),
            ];
        }
        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    private static function applyDynamicPriceCopy(array $page, string $serviceKey): array
    {
        $price = self::money((float)$page['store_price_value']);
        $minimum = (int)$page['minimum_guests'];

        switch ($serviceKey) {
            case 'pasta':
                $page['meta_title'] = 'Live Pasta Station Catering South Florida | From ' . $price . ' | VNV';
                $page['meta_description'] = 'Live pasta station catering in South Florida from ' . $price . ' per guest, with a ' . $minimum . '-guest minimum and selections from the current VNV Store menu.';
                $page['price_note'] = 'The main station begins at the published ' . $price . ' per-guest price. Product variations and add-ons are priced separately.';
                break;
            case 'appetizer':
                $page['meta_description'] = 'Appetizer catering in South Florida from ' . $price . ' per guest with a ' . $minimum . '-guest minimum and seven pieces per person. Choose Signature or Ocean Blue.';
                $page['price_note'] = 'The base collections are ' . $price . ' per guest with a ' . $minimum . '-guest minimum. Extra bites and other add-ons are priced separately.';
                break;
            case 'crepes':
                $page['meta_description'] = 'Book a live crepe station in South Florida from ' . $price . ' per guest with a ' . $minimum . '-guest minimum, sweet and savory fillings, and live chefs.';
                $page['price_note'] = 'The published base package starts at ' . $price . ' per guest. Delivery, taxes, upgrades and venue requirements may affect the final proposal.';
                if (isset($page['faqs'][0])) {
                    $page['faqs'][0]['answer'] = 'The current published price starts at ' . $price . ' per guest with a ' . $minimum . '-guest minimum.';
                }
                break;
            case 'taco':
                $page['meta_title'] = 'Live Taco Station Catering South Florida | From ' . $price . ' | VNV';
                $page['meta_description'] = 'Book a live taco and burrito station in South Florida from ' . $price . ' per guest with a ' . $minimum . '-guest minimum and current VNV Store selections.';
                $page['price_note'] = 'The published menu starts at ' . $price . ' per guest. Appetizers, desserts and other combinations are priced separately.';
                break;
            case 'corporate':
                $mealBoxValue = (float)($page['meal_box_price_value'] ?? 0);
                $mealBoxPrice = $mealBoxValue > 0 ? self::money($mealBoxValue) : null;
                $page['meta_description'] = 'Corporate catering in South Florida with staffed food stations from ' . $price . ' per guest for ' . $minimum . '+ guests.'
                    . ($mealBoxPrice !== null ? ' Separate meal boxes start at ' . $mealBoxPrice . '.' : '');
                $page['price_note'] = 'Staffed stations from ' . $price . ' and meal boxes are separate service formats. Taxes, delivery, staffing, packaging and menu upgrades may affect the final total.';
                if ($mealBoxPrice !== null && isset($page['faqs'][0])) {
                    $page['faqs'][0]['answer'] = 'VNV corporate breakfast and lunch meal boxes start at ' . $mealBoxPrice . ' per person. Final pricing depends on menu, quantity, packaging and delivery.';
                }
                if (isset($page['faqs'][1])) {
                    $page['faqs'][1]['answer'] = 'The current station program starts at ' . $price . ' per guest and requires at least ' . $minimum . ' guests.';
                }
                break;
        }

        return $page;
    }

    /** @return array{whatsapp_number:string,corporate_meal_box_price:?float} */
    private static function tenantSettings(): array
    {
        $settings = ['whatsapp_number' => '13053761210', 'corporate_meal_box_price' => null];
        try {
            $db = new Connection();
            $db->query("SELECT staff_whatsapp,corporate_meal_box_price FROM store_gourmet_settings WHERE id_owner=:owner AND site_key=:site ORDER BY id LIMIT 1");
            $db->bind(':owner', AvomealContext::ownerId());
            $db->bind(':site', SiteContext::siteKey());
            $row = $db->fetchOne();
            $number = preg_replace('/\D+/', '', (string)($row->staff_whatsapp ?? ''));
            if ($number !== '') {
                $settings['whatsapp_number'] = $number;
            }
            if ((float)($row->corporate_meal_box_price ?? 0) > 0) {
                $settings['corporate_meal_box_price'] = (float)$row->corporate_meal_box_price;
            }
        } catch (Throwable $e) {
            error_log('Catering landing tenant settings fallback: ' . $e->getMessage());
            try {
                $db = new Connection();
                $db->query("SELECT staff_whatsapp FROM store_gourmet_settings WHERE id_owner=:owner AND site_key=:site ORDER BY id LIMIT 1");
                $db->bind(':owner', AvomealContext::ownerId());
                $db->bind(':site', SiteContext::siteKey());
                $row = $db->fetchOne();
                $number = preg_replace('/\D+/', '', (string)($row->staff_whatsapp ?? ''));
                if ($number !== '') {
                    $settings['whatsapp_number'] = $number;
                }
            } catch (Throwable $fallbackError) {
                error_log('Catering landing WhatsApp fallback failed: ' . $fallbackError->getMessage());
            }
        }
        return $settings;
    }

    private static function isAdsMode(): bool
    {
        $source = strtolower(trim((string)($_GET['utm_source'] ?? '')));
        foreach (['gclid', 'gbraid', 'wbraid'] as $key) {
            if (trim((string)($_GET[$key] ?? '')) !== '') {
                return true;
            }
        }
        return $source === 'google' || (string)($_COOKIE['vnv_ads_session'] ?? '') === '1';
    }

    private static function hasPaidAttribution(): bool
    {
        foreach (['gclid', 'gbraid', 'wbraid'] as $key) {
            if (trim((string)($_GET[$key] ?? $_COOKIE['vnv_' . $key] ?? '')) !== '') {
                return true;
            }
        }
        $source = strtolower(trim((string)($_GET['utm_source'] ?? $_COOKIE['vnv_utm_source'] ?? '')));
        return $source === 'google';
    }

    private static function refCode(string $serviceCode): string
    {
        if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['vnv_gourmet_ref_suffix'])) {
            $_SESSION['vnv_gourmet_ref_suffix'] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 3));
        }
        $suffix = (string)($_SESSION['vnv_gourmet_ref_suffix'] ?? strtoupper(substr(hash('sha256', session_id() ?: 'web'), 0, 3)));
        return (self::hasPaidAttribution() ? 'GA' : 'WEB') . '-' . $serviceCode . '-' . $suffix;
    }

    private static function landingNotice(): array
    {
        if (isset($_GET['lead']) && $_GET['lead'] === 'success') {
            return ['type' => 'success', 'message' => 'Thank you. Your catering request was received and our team will follow up.'];
        }
        if (isset($_GET['lead_error'])) {
            return ['type' => 'error', 'message' => 'Please review the information and try again.'];
        }
        return [];
    }

    private static function priceLabel(float $price): string
    {
        return 'From ' . self::money($price) . ' per guest';
    }

    private static function money(float $price): string
    {
        return '$' . number_format($price, fmod($price, 1.0) === 0.0 ? 0 : 2);
    }

    private static function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
