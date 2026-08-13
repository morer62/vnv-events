<?php

namespace App\Services;

use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use Throwable;

final class CateringLandingProductService
{
    public static function hydrate(array $page): array
    {
        $page['store_price_label'] = $page['price'];
        $page['store_price_value'] = self::numberFromLabel((string)$page['price']);
        $page['store_price_updated'] = false;

        $slug = trim((string)($page['product_slug'] ?? ''));
        if ($slug === '' && !empty($page['product_url'])) {
            $path = trim((string)(parse_url((string)$page['product_url'], PHP_URL_PATH) ?? ''), '/');
            $segments = explode('/', $path);
            $slug = (string)end($segments);
        }
        if ($slug === '') {
            return $page;
        }

        try {
            $repository = new StoreProductsRepository();
            $product = $repository->getPublicBySlug($slug, AvomealContext::ownerId(), SiteContext::siteKey());
            if (!$product) {
                return $page;
            }

            $price = self::primaryServicePrice($repository, $product);
            if ($price <= 0) {
                return $page;
            }

            $page['store_price_value'] = $price;
            $page['store_price_label'] = 'From $' . number_format($price, 2) . ' p/p';
            $page['store_price_updated'] = true;
            $page['store_product_name'] = (string)($product->name ?? $page['h1']);

            if (isset($page['schema']['offers']) && is_array($page['schema']['offers'])) {
                $page['schema']['offers']['price'] = number_format($price, 2, '.', '');
                $page['schema']['offers']['lowPrice'] = number_format($price, 2, '.', '');
                $page['schema']['offers']['url'] = $page['product_url'];
                $page['schema']['offers']['availability'] = 'https://schema.org/InStock';
            }
        } catch (Throwable $e) {
            error_log('Catering landing live price fallback for ' . $slug . ': ' . $e->getMessage());
        }

        return $page;
    }

    private static function primaryServicePrice(StoreProductsRepository $repository, object $product): float
    {
        if ((int)($product->is_variable ?? 0) !== 1) {
            return (float)($product->display_price ?? $product->effective_price ?? $product->price ?? 0);
        }

        $details = $repository->getFullPublicProductDetails(
            (int)$product->id,
            AvomealContext::ownerId(),
            SiteContext::siteKey()
        );

        $stationPrices = [];
        foreach (($details->variations ?? []) as $variation) {
            $name = strtolower(trim((string)($variation->name ?? '')));
            if ($name === '' || !str_contains($name, 'station') || str_contains($name, '+')) {
                continue;
            }
            $value = (float)($variation->effective_price ?? $variation->price ?? 0);
            if ($value > 0) {
                $stationPrices[] = $value;
            }
        }

        return $stationPrices !== []
            ? min($stationPrices)
            : (float)($product->display_price ?? $product->min_price ?? 0);
    }

    private static function numberFromLabel(string $label): float
    {
        return preg_match('/\$\s*([0-9]+(?:\.[0-9]+)?)/', $label, $matches)
            ? (float)$matches[1]
            : 0.0;
    }
}
