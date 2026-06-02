# VNV Events Public Forums

> Avomeal web note: this is retained as VNV Events forum reference. It is not the main Avomeal food/store flow unless future community features are explicitly requested.

## Purpose

The public forum is a standalone community module for VNV Events. It is not blog comments, CMS pages, private chat, or internal order messaging.

## Roles

- Level 1 can create, edit, publish, pause, lock, hide, and moderate forum threads and replies.
- Level 5 can read public threads, reply to published/unlocked threads, and view their own replies from the panel.
- Visitors can read published threads and approved replies. They cannot reply anonymously.

## Public URLs

- Forum list: `/forums/`
- Thread detail: `/forums/{slug}/`
- Legacy `/forum/topic?id={id}` remains internally compatible, but new public links should use slugs.

## Signup From Forum

The thread page includes a quick signup modal for visitors. It creates a Level 5 account, logs the user in immediately, and returns them to the same thread instead of sending them through the business/Ophyra signup flow.

This flow must not create business accounts, memberships, modules, or onboarding tasks.

## Moderation

Replies use `status` and `is_public`.

- `APPROVED`: visible publicly.
- `PENDING`: submitted but not visible.
- `HIDDEN`: hidden by admin.
- `REJECTED`: rejected by admin.
- `DELETED`: soft-deleted.

Set `FORUM_REPLIES_REQUIRE_APPROVAL=true` to make new Level 5 replies enter as pending. If the setting is absent or false, Level 5 replies publish immediately.

## SEO

Each public thread receives:

- title and meta description,
- canonical URL,
- Open Graph/Twitter metadata through the shared public layout,
- JSON-LD using `WebPage`, `DiscussionForumPosting`, `BreadcrumbList`, `Organization`, and `WebSite`.

Forum topics are included in `/sitemap.xml` after the migration is applied.

## Required Migration

Run:

```bash
vendor/bin/phinx migrate
```

The migration adds slug, status, SEO, publish, moderation, and soft-delete fields to the existing forum tables.
