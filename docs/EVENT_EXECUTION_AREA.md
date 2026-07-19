# Event Execution Area

Private event workspace available at `/panel/event-execution` for Level 1, confirmed Level 4 team members, the order's Level 5 client, and registered participants who join with the five-digit event code.

## Initial features

- Five-digit code unique within the operating owner.
- Karaoke queue: participant adds/removes their own song; Level 1 and Level 4 can manage the full queue.
- Karaoke and song-request changes are detected every 2.5 seconds. Idle screens refresh automatically; screens with an in-progress form show a safe update banner instead of discarding typed content.
- Song requests with optional DJ tips. Tips can be charged from a saved Stripe/Square method scoped to the event owner and are recorded in `event_execution_tip_payments` only after provider approval.
- General event gallery, separate from closure-report photos.
- Maximum 10 active JPG/PNG/WEBP photos per user/event, 10 MB each.
- Photos expire 60 days after upload. The uploader can remove their own photo; the order client and Level 1 can remove any photo.

## Required SQL

Apply `db/event_execution_area_required.sql` before opening the module.

## Follow-up

- Schedule `php src/cron/event-execution-photo-cleanup.php` daily to delete expired Cloudinary assets and soft-delete their rows.
- Direct card tokenization and PayPal tips are not enabled in this first payment surface; participants without a saved Stripe/Square method can still submit a song request without a tip.
