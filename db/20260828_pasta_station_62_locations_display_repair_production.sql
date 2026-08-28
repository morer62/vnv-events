-- VNV Events Pasta Station 62-city display repair.
-- Repairs polluted titles, Cloudinary image URLs, and cycle-wide contrast defects.
-- Execute only on production after a full verified backup.
SET NAMES utf8mb4;
DELIMITER $$
DROP PROCEDURE IF EXISTS vnv_pasta_display_repair_preflight$$
CREATE PROCEDURE vnv_pasta_display_repair_preflight()
BEGIN
  IF DATABASE() <> 'ophyra_vnv_venue' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong database: expected ophyra_vnv_venue';
  END IF;
  IF (SELECT COUNT(*) FROM cms_contents WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'pasta-station-catering-%') <> 62 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Expected exactly 62 Pasta Station location rows';
  END IF;
  IF (SELECT COUNT(*) FROM cms_contents WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'pasta-station-catering-%' AND title REGEXP 'Thought for|Searched the web|<vnv_location|CDATA' AND (target_location IS NULL OR target_location NOT LIKE '%, FL')) <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A polluted title has no normalized target location';
  END IF;
  IF (SELECT COUNT(*) FROM cms_contents WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'pasta-station-catering-%' AND body_html LIKE '%</style>%') <> 62 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pasta Station style blocks are not complete';
  END IF;
END$$
CALL vnv_pasta_display_repair_preflight()$$
DROP PROCEDURE vnv_pasta_display_repair_preflight$$
DELIMITER ;

START TRANSACTION;

UPDATE cms_contents
SET target_location='Weston, FL'
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug='pasta-station-catering-weston'
  AND (target_location IS NULL OR target_location='');

UPDATE cms_contents
SET title=CONCAT('Pasta Station Catering in ', SUBSTRING_INDEX(target_location, ', FL', 1), ', Florida')
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug LIKE 'pasta-station-catering-%'
  AND title REGEXP 'Thought for|Searched the web|<vnv_location|CDATA';

UPDATE cms_contents
SET featured_image_url='https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp'
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug LIKE 'pasta-station-catering-%'
  AND featured_image_url <> 'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp';

UPDATE cms_contents
SET body_html=REGEXP_REPLACE(
  REGEXP_REPLACE(
    body_html,
    '\\{\\{[[:space:]]*asset_for\\([[:space:]]*[''"](?:/vnv-events/)?assets/images/service_photos/pasta_station_1\\.webp[''"][[:space:]]*\\)[[:space:]]*\\}\\}',
    'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp'
  ),
  '\\{\\{[[:space:]]*asset_for\\([[:space:]]*[''"](?:/vnv-events/)?assets/images/service_photos/pasta_station_2_lite\\.webp[''"][[:space:]]*\\)[[:space:]]*\\}\\}',
  'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926831/ophyra-growth-hub/vnvevents/service-photos/pasta-station-2-lite.webp'
)
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug LIKE 'pasta-station-catering-%';

UPDATE cms_contents
SET body_html=REPLACE(
  body_html,
  '</style>',
  CONCAT(
    '/* vnv-location-contrast-guard */',
    '.wn-hero{background-color:#090c0d;color:#fff}',
    '.wn-hero h1,.wn-hero .wn-lead,.wn-hero .wn-trust,.wn-hero .wn-trust span{color:#fff!important}',
    '.wn-hero .wn-eyebrow{color:#7de0dd!important}',
    '.wn-hero .wn-btn.alt{color:#7de0dd!important;border-color:#7de0dd!important;background:rgba(9,12,13,.3)!important}',
    '.wn-card.dark,.wn-card.dark h2,.wn-card.dark h3{color:#fff!important}',
    '.wn-card.dark p,.wn-card.dark li,.wn-card.dark small{color:rgba(255,255,255,.86)!important}',
    '.wn-cta{background-color:#090c0d;color:#fff!important}',
    '.wn-cta h2,.wn-cta p,.wn-cta .wn-fineprint{color:#fff!important}',
    '.wn-cta .wn-btn.alt{color:#7de0dd!important;border-color:#7de0dd!important;background:transparent!important}',
    '.wn-light .wn-inline,.wn-light .wn-related a,.wn-light a.wn-inline{color:#066b68!important}',
    '.wn-btn{color:#101313!important}',
    '</style>'
  )
)
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug LIKE 'pasta-station-catering-%'
  AND body_html NOT LIKE '%vnv-location-contrast-guard%';

COMMIT;

SELECT
  COUNT(*) AS total_pages,
  SUM(title REGEXP 'Thought for|Searched the web|<vnv_location|CDATA') AS polluted_titles,
  SUM(featured_image_url <> 'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp') AS malformed_images,
  SUM(body_html NOT LIKE '%vnv-location-contrast-guard%') AS missing_contrast_guards,
  SUM(body_html REGEXP '(?:/vnv-events/)?assets/images/service_photos/pasta_station_|asset_for\\([^)]*pasta.station') AS local_image_references
FROM cms_contents
WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'pasta-station-catering-%';
