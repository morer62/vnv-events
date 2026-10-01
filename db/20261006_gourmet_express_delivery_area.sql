-- Limits Palm Beach County delivery to the southern service area.
-- Broward and Miami-Dade remain county-wide.
SET NAMES utf8mb4;

UPDATE store_gourmet_delivery_zones
SET zone_name='South Palm Beach',
    zip_codes_json=JSON_ARRAY(
      '33426','33428','33431','33432','33433','33434','33435','33436','33437',
      '33444','33445','33446','33472','33473','33483','33484','33486','33487',
      '33496','33498'
    ),
    updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND county_name='Palm Beach County';

SELECT 'VNV Gourmet Express delivery area ready' AS result;
