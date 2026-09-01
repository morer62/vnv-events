-- Register the two additional brands that share Ophyra's database.
-- Content remains isolated by (id_owner, site_key).
SET NAMES utf8mb4;

INSERT INTO growth_sites
  (id_owner,site_key,site_name,domain,public_base_url,default_language,brand_voice,target_locations,main_services,main_products,default_cta_label,default_cta_url,cloudinary_folder,auto_publish_allowed,sitemap_settings,route_rules,status)
VALUES
  (2,'miamitechlab','Tech Lab Miami','miamitechlab.com','https://miamitechlab.com','en',
   'Clear, practical, technically rigorous, locally grounded and useful to South Florida founders and operators.',
   JSON_ARRAY('Miami-Dade County','Broward County','Palm Beach County','Miami, Florida','Fort Lauderdale, Florida','West Palm Beach, Florida'),
   JSON_ARRAY(JSON_OBJECT('label','AI consulting','url','/services/ai-consulting/'),JSON_OBJECT('label','business automation','url','/services/business-automation/'),JSON_OBJECT('label','software and digital systems','url','/services/')),
   JSON_ARRAY(),'Start a Project','/contact/','ophyra-growth-hub/miamitechlab',0,
   JSON_OBJECT('public_base_url','https://miamitechlab.com','sitemap_url','https://miamitechlab.com/sitemap.xml','environment','production'),
   JSON_OBJECT('page','/{slug}','landing','/{slug}','custom','/{slug}','location','/locations/{slug}','blog','/blog/{slug}'),'active')
ON DUPLICATE KEY UPDATE site_name=VALUES(site_name),domain=VALUES(domain),public_base_url=VALUES(public_base_url),brand_voice=VALUES(brand_voice),target_locations=VALUES(target_locations),main_services=VALUES(main_services),cloudinary_folder=VALUES(cloudinary_folder),sitemap_settings=VALUES(sitemap_settings),route_rules=VALUES(route_rules),status='active';

INSERT INTO growth_sites
  (id_owner,site_key,site_name,domain,public_base_url,default_language,brand_voice,target_locations,main_services,main_products,default_cta_label,default_cta_url,cloudinary_folder,auto_publish_allowed,sitemap_settings,route_rules,status)
VALUES
  (2,'avomeal','The Pasta Station','thepastastation.net','https://thepastastation.net','en',
   'Warm, food-forward, trustworthy and practical, with an emphasis on chef-prepared pasta and South Florida service.',
   JSON_ARRAY('Miami-Dade County','Broward County','Palm Beach County','Miami, Florida','Doral, Florida','Fort Lauderdale, Florida','Hollywood, Florida','Weston, Florida','West Palm Beach, Florida'),
   JSON_ARRAY(JSON_OBJECT('label','live pasta station events','url','/book-a-pasta-station/'),JSON_OBJECT('label','weekly pasta','url','/weekly-pasta/'),JSON_OBJECT('label','family pasta trays','url','/store/')),
   JSON_ARRAY(JSON_OBJECT('label','prepared pasta meals','url','/store/'),JSON_OBJECT('label','family trays','url','/store/')),
   'Order or Book a Pasta Station','/book-a-pasta-station/','ophyra-growth-hub/avomeal',0,
   JSON_OBJECT('public_base_url','https://thepastastation.net','sitemap_url','https://thepastastation.net/sitemap.xml','environment','production'),
   JSON_OBJECT('page','/{slug}','landing','/{slug}','custom','/{slug}','location','/locations/{slug}','blog','/blog/{slug}'),'active')
ON DUPLICATE KEY UPDATE site_name=VALUES(site_name),domain=VALUES(domain),public_base_url=VALUES(public_base_url),brand_voice=VALUES(brand_voice),target_locations=VALUES(target_locations),main_services=VALUES(main_services),main_products=VALUES(main_products),default_cta_label=VALUES(default_cta_label),default_cta_url=VALUES(default_cta_url),cloudinary_folder=VALUES(cloudinary_folder),sitemap_settings=VALUES(sitemap_settings),route_rules=VALUES(route_rules),status='active';

SELECT site_key,site_name,public_base_url,cloudinary_folder FROM growth_sites WHERE id_owner=2 ORDER BY site_key;
