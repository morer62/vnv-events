ALTER TABLE cms_categories
    ADD COLUMN IF NOT EXISTS applies_to_pages TINYINT(1) NOT NULL DEFAULT 1 AFTER featured_image_alt,
    ADD COLUMN IF NOT EXISTS applies_to_blog TINYINT(1) NOT NULL DEFAULT 1 AFTER applies_to_pages,
    ADD COLUMN IF NOT EXISTS applies_to_locations TINYINT(1) NOT NULL DEFAULT 1 AFTER applies_to_blog;

ALTER TABLE cms_contents
    ADD COLUMN IF NOT EXISTS id_cms_category INT NULL AFTER id_blog_category;

UPDATE cms_categories
SET applies_to_pages = 1
WHERE applies_to_pages IS NULL;

UPDATE cms_categories
SET applies_to_blog = 1
WHERE applies_to_blog IS NULL;

UPDATE cms_categories
SET applies_to_locations = 1
WHERE applies_to_locations IS NULL;
