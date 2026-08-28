<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

const CONTRAST_GUARD = <<<'CSS'

/* vnv-location-contrast-guard */
.wn-hero{background-color:#090c0d;color:#fff}
.wn-hero h1,.wn-hero .wn-lead,.wn-hero .wn-trust,.wn-hero .wn-trust span{color:#fff!important}
.wn-hero .wn-eyebrow{color:#7de0dd!important}
.wn-hero .wn-btn.alt{color:#7de0dd!important;border-color:#7de0dd!important;background:rgba(9,12,13,.3)!important}
.wn-card.dark,.wn-card.dark h2,.wn-card.dark h3{color:#fff!important}
.wn-card.dark p,.wn-card.dark li,.wn-card.dark small{color:rgba(255,255,255,.86)!important}
.wn-cta{background-color:#090c0d;color:#fff!important}
.wn-cta h2,.wn-cta p,.wn-cta .wn-fineprint{color:#fff!important}
.wn-cta .wn-btn.alt{color:#7de0dd!important;border-color:#7de0dd!important;background:transparent!important}
.wn-light .wn-inline,.wn-light .wn-related a,.wn-light a.wn-inline{color:#066b68!important}
.wn-btn{color:#101313!important}
CSS;

const PASTA_IMAGE_1 = 'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp';
const PASTA_IMAGE_2 = 'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926831/ophyra-growth-hub/vnvevents/service-photos/pasta-station-2-lite.webp';

$root = dirname(__DIR__);
$files = array_merge(
    [$root . '/db/20260827_pasta_station_catering_62_locations_production.sql'],
    glob($root . '/db/20260827_pasta_station_locations_batch_*_local.sql') ?: []
);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$file}");
    }
    $changed = 0;
    $sql = preg_replace_callback(
        '/INSERT INTO cms_contents\(([^\r\n]+)\) VALUES\(([^\r\n]+)\);/',
        static function (array $match) use (&$changed): string {
            $columns = str_getcsv($match[1]);
            $values = splitSqlValues($match[2]);
            $bodyIndex = array_search('body_html', $columns, true);
            $imageIndex = array_search('featured_image_url', $columns, true);
            if ($bodyIndex === false || $imageIndex === false || count($columns) !== count($values)) {
                throw new RuntimeException('Malformed cms_contents INSERT');
            }
            $body = decodeHexConvert($values[$bodyIndex]);
            $normalized = normalizeImageUrls(addContrastGuard($body));
            if ($normalized !== $body) {
                $values[$bodyIndex] = toHexConvert($normalized);
                ++$changed;
            }
            $values[$imageIndex] = toHexConvert(PASTA_IMAGE_1);
            return 'INSERT INTO cms_contents(' . $match[1] . ') VALUES(' . implode(',', $values) . ');';
        },
        $sql
    );
    if ($sql === null) {
        throw new RuntimeException("Regex failure in {$file}");
    }
    if (file_put_contents($file, $sql) === false) {
        throw new RuntimeException("Cannot write {$file}");
    }
    echo basename($file) . ": {$changed} bodies updated\n";
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=ophyra;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rows = $pdo->query("SELECT id, slug, body_html FROM cms_contents WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'pasta-station-catering-%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 62) {
    throw new RuntimeException('Expected 62 local Pasta Station rows');
}
$pdo->beginTransaction();
$update = $pdo->prepare('UPDATE cms_contents SET body_html=:body, featured_image_url=:image WHERE id=:id');
$updated = 0;
foreach ($rows as $row) {
    $body = normalizeImageUrls(addContrastGuard((string) $row['body_html']));
    if ($body !== $row['body_html']) {
        $update->execute(['body' => $body, 'image' => PASTA_IMAGE_1, 'id' => (int) $row['id']]);
        ++$updated;
    } else {
        $update->execute(['body' => $body, 'image' => PASTA_IMAGE_1, 'id' => (int) $row['id']]);
    }
}
$pdo->commit();
echo "local database: {$updated} bodies updated\n";

$validated = 0;
foreach ($rows as $row) {
    $_SERVER['REQUEST_URI'] = '/locations/' . $row['slug'] . '/';
    $rendered = App\Utils\TemplateResponse::renderString(normalizeImageUrls(addContrastGuard((string) $row['body_html'])));
    if (preg_match('/\{%\s*(?:extends|block|include|set)|\{\{\s*(?:asset_for|path)\s*\(/', $rendered)) {
        throw new RuntimeException('Unresolved Twig token after rendering ' . $row['slug']);
    }
    if (preg_match('/Thought for|Searched the web|<vnv_location|CDATA/', $rendered)) {
        throw new RuntimeException('Provider contamination after rendering ' . $row['slug']);
    }
    if (!str_contains($rendered, 'vnv-location-contrast-guard')) {
        throw new RuntimeException('Contrast guard missing after rendering ' . $row['slug']);
    }
    if (preg_match('#(?:/vnv-events/)?assets/images/service_photos/pasta_station_[^\"\')\s]+#', $rendered)) {
        throw new RuntimeException('Local production image path after rendering ' . $row['slug']);
    }
    ++$validated;
}
echo "Twig compilation: {$validated} pages passed\n";

function addContrastGuard(string $body): string
{
    if (str_contains($body, 'vnv-location-contrast-guard')) {
        return $body;
    }
    $position = strripos($body, '</style>');
    if ($position === false) {
        throw new RuntimeException('Missing closing style tag');
    }
    return substr($body, 0, $position) . CONTRAST_GUARD . "\n" . substr($body, $position);
}

function normalizeImageUrls(string $body): string
{
    $replacements = [
        'pasta_station_1.webp' => PASTA_IMAGE_1,
        'pasta_station_2_lite.webp' => PASTA_IMAGE_2,
    ];
    foreach ($replacements as $filename => $url) {
        $pattern = '/\{\{\s*asset_for\(\s*[\'\"](?:\/vnv-events\/)?assets\/images\/service_photos\/' . preg_quote($filename, '/') . '[\'\"]\s*\)\s*\}\}/';
        $body = preg_replace($pattern, $url, $body) ?? throw new RuntimeException('Image expression replacement failed');
        $body = str_replace([
            '/vnv-events/assets/images/service_photos/' . $filename,
            'assets/images/service_photos/' . $filename,
        ], $url, $body);
    }
    return $body;
}

function decodeHexConvert(string $value): string
{
    if (!preg_match('/^CONVERT\(0x([0-9a-f]+) USING utf8mb4\)$/i', $value, $match)) {
        throw new RuntimeException('Expected a hex-encoded field');
    }
    $decoded = hex2bin($match[1]);
    if ($decoded === false) {
        throw new RuntimeException('Invalid hex field');
    }
    return $decoded;
}

function toHexConvert(string $value): string
{
    return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4)';
}

/** @return list<string> */
function splitSqlValues(string $input): array
{
    $values = [];
    $start = 0;
    $depth = 0;
    $quoted = false;
    $length = strlen($input);
    for ($i = 0; $i < $length; ++$i) {
        $char = $input[$i];
        if ($quoted) {
            if ($char === "'" && ($i + 1 >= $length || $input[$i + 1] !== "'")) {
                $quoted = false;
            } elseif ($char === "'" && $i + 1 < $length && $input[$i + 1] === "'") {
                ++$i;
            }
            continue;
        }
        if ($char === "'") {
            $quoted = true;
        } elseif ($char === '(') {
            ++$depth;
        } elseif ($char === ')') {
            --$depth;
        } elseif ($char === ',' && $depth === 0) {
            $values[] = substr($input, $start, $i - $start);
            $start = $i + 1;
        }
    }
    $values[] = substr($input, $start);
    return $values;
}
