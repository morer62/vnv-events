# AI Content Approval Workflow

Review panel:

```text
/panel/ai-content/review
```

Access is protected by `AI_CONTENT_REVIEW_PASSWORD`. A successful unlock is remembered with session/cookie state for `AI_CONTENT_REVIEW_REMEMBER_DAYS`, default 30 days.

This review flow applies to VNV Events first. The same tables and panel can review Avomeal drafts when `site_key` is switched to `avomeal`.

## Statuses

- `IDEA`
- `DRAFT`
- `NEEDS_REVIEW`
- `REVISION_REQUESTED`
- `APPROVED`
- `PUBLISHED`
- `REJECTED`
- `ARCHIVED`

## Admin Actions

- Approve: moves a draft to `APPROVED`.
- Publish: only available for `APPROVED` drafts.
- Save edits: lets the reviewer manually adjust title, slug, SEO fields, body HTML, schema, FAQ, image URL and thumbnail prompt before approval.
- Reject: moves a draft to `REJECTED`.
- Request changes: moves a draft to `REVISION_REQUESTED`.
- Regenerate: asks OpenAI to rewrite the draft and returns it to `NEEDS_REVIEW`.
- Archive: moves a draft to `ARCHIVED`.
- Upload voice note: saves audio with Cloudinary and links it to the draft. Transcription is pending for a later phase.

## Publishing Rules

Publishing is a human action. It creates public CMS/location records and marks them visible in `site_visibility`.

Do not allow AI-generated content to publish without human approval.
