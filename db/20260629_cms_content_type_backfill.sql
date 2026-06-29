UPDATE cms_contents
SET type = 'page'
WHERE LOWER(COALESCE(content_type, '')) = 'location'
  AND LOWER(COALESCE(type, '')) <> 'page'
  AND LOWER(COALESCE(site_key, origin_site_key, '')) = 'vnvevents';

UPDATE cms_contents
SET type = 'post'
WHERE LOWER(COALESCE(content_type, '')) IN ('blog', 'blog_post', 'post')
  AND LOWER(COALESCE(type, '')) <> 'post'
  AND LOWER(COALESCE(site_key, origin_site_key, '')) = 'vnvevents';
