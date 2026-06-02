# AI Content SEO Schema Rules

Generated content must be useful, specific and human-reviewable.

## Content Rules

- Avoid generic AI phrases and filler.
- Do not invent offices, addresses, reviews, ratings, guarantees, awards, licenses, certifications, pricing or staff names.
- Do not claim service availability that the admin has not verified.
- Keep VNV Events content focused on event services and verified operating areas.
- Keep Avomeal content focused on meal preps, holiday menus, party boxes and prepared food when the profile is switched to `avomeal`.

## Location Pages

Location pages must not create fake city-specific businesses.

Use JSON-LD `Service` with `areaServed` for city/service targeting. Do not use fake `LocalBusiness` records for every city.

## Blog Posts

Blog posts can use relevant schema such as:

- `BlogPosting`
- `FAQPage`
- `HowTo`, only when the content is actually instructional

## Visibility

Published content is not public unless `site_visibility` has:

```text
site_key = target site
entity_type = cms_content or location_page
entity_id = published record id
is_visible = 1
visibility_status = VISIBLE
```

The publishing service creates that visibility row after manual publish.
