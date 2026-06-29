UPDATE cms_contents
SET type = 'location'
WHERE LOWER(COALESCE(content_type, '')) = 'location'
  AND LOWER(COALESCE(type, '')) <> 'location';

UPDATE cms_contents
SET type = 'post'
WHERE LOWER(COALESCE(content_type, '')) IN ('blog', 'blog_post', 'post')
  AND LOWER(COALESCE(type, '')) <> 'post';

UPDATE cms_contents
SET canonical_url = CONCAT('https://vnvevents.com/locations/', slug, '/')
WHERE LOWER(COALESCE(content_type, '')) = 'location'
  AND COALESCE(slug, '') <> ''
  AND (
    canonical_url IS NULL
    OR canonical_url = ''
    OR canonical_url REGEXP '^https?://(www\\.)?vnvevents\\.com/[^/]+/?$'
  );

UPDATE cms_contents
SET canonical_url = CONCAT('https://vnvevents.com/blog/', slug, '/')
WHERE LOWER(COALESCE(content_type, '')) IN ('blog', 'blog_post', 'post')
  AND COALESCE(slug, '') <> ''
  AND (
    canonical_url IS NULL
    OR canonical_url = ''
    OR canonical_url REGEXP '^https?://(www\\.)?vnvevents\\.com/[^/]+/?$'
  );
