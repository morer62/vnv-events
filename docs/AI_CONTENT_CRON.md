# AI Content Cron

Daily generator:

```bash
php src/cron/ai-content-daily.php
```

Recommended cadence: once per day.

## Behavior

- Reads `.env`.
- Uses `AI_CONTENT_SITE_KEY`, defaulting to `vnv_events`.
- Reads DB settings from `ai_content_settings` when installed.
- Falls back to env/default settings when no DB setting exists.
- Respects `max_pending_drafts`.
- Skips generation when `enabled` is false.
- Creates only `NEEDS_REVIEW` drafts.
- Never publishes.

For Avomeal, change:

```text
AI_CONTENT_SITE_KEY=avomeal
```

## Failure Modes

- Missing SQL: exits with an error that `db/ai_content_assistant_required.sql` must be applied.
- Missing `OPENAI_TOKEN`: exits without creating drafts.
- Duplicate slug/topic: skips that draft after a retry with a different angle.

## Windows Task Scheduler Example

Program:

```text
C:\xampp\php\php.exe
```

Arguments:

```text
C:\xampp\htdocs\vnv-events\src\cron\ai-content-daily.php
```

Start in:

```text
C:\xampp\htdocs\vnv-events
```
