# VNV Video Render Production Setup

The Video Studio render engine uses the FFmpeg system executable. It is not a Composer package.

## Ubuntu or Debian

```bash
sudo apt-get update
sudo apt-get install -y ffmpeg
which ffmpeg
```

Add the returned path to the production environment:

```dotenv
FFMPEG_PATH=/usr/bin/ffmpeg
```

## Windows Server

Run from an administrator terminal:

```powershell
winget install --id Gyan.FFmpeg --exact --accept-package-agreements --accept-source-agreements
where.exe ffmpeg
```

Set `FFMPEG_PATH` to the complete `ffmpeg.exe` location and restart Apache/PHP.

## Hosting without administrator permissions

Download a trusted static FFmpeg build for the server operating system, store it outside the public web root, grant execute permission and configure its absolute path. Do not place executables inside `public/`.

## Background worker

Run this command every minute. Each invocation processes one queued render to avoid exhausting the server:

```bash
php src/cron/ai-video-render-worker.php 1
```

Linux cron example:

```cron
* * * * * cd /absolute/path/to/vnv-events && /usr/bin/php src/cron/ai-video-render-worker.php 1 >> storage/logs/ai-video-render.log 2>&1
```

The worker atomically claims one job, downloads its private sources, applies approved keep-cuts, color treatment, animated callouts, captions, logo, overlay and background-audio mix, normalizes loudness, adds selected intro/outro clips, exports the selected aspect ratio, uploads the optimized MP4 to Cloudinary and removes its temporary local files.

Caption cleanup is non-destructive: every manual or AI edit creates a project revision. Removing selected transcript text or a configured phrase removes matching caption blocks and stores their timestamps as video/audio exclusions for the next render. Generated visual props are saved in the edit plan and overlaid only during their approved time range.

## Dynamic social editing

The editor supports:

- vertical Reel/Short export at `1080x1920`;
- horizontal HD at `1920x1080`;
- horizontal 4K at `3840x2160`;
- kinetic ASS captions synchronized word by word, including highlighted emphasis words;
- AI-planned punch-ins, punch-outs, reframing and zoom intervals;
- timestamped flash and dip-to-black transitions;
- low, balanced or energetic motion direction entered from the project editor.

The `marketing_educator` preset adapts the pacing conventions of current educational social video while preserving VNV branding. It must not copy another creator's logo, likeness, wording or exact visual compositions.

## Browser editing workspace

The browser workspace uses locally hosted production assets rather than runtime CDN dependencies:

- `wavesurfer.js 7.12.11` supplies the audio waveform, scrubber, zoomable time ruler and draggable/resizable caption regions;
- `Konva 10.3.0` supplies direct manipulation of text overlays on the video preview;
- FFmpeg remains the authoritative server renderer for HD, vertical and 4K output.

Dependencies are declared in `package.json`, locked in `package-lock.json` and copied to `public/assets/lib` so production does not require Node.js at request time. The editor stores overlay positions as normalized coordinates, allowing the same layout to scale across preview and final export resolution.
# Large originals through the private SFTP inbox

Do not send production originals of 500 MB–10 GB through the browser. Use the
portable project-relative configuration:

```env
VIDEO_INGEST_PATH=storage/video-ingest/incoming
```

The application resolves this value from the repository root using its real
filesystem location, independently of `APP_URL` and independently of the
server account path. The project already routes public requests through
`public/`, so repository-level `storage/` remains outside the public document
directory. Grant the SFTP account and PHP/render-worker account read/write
access to this directory.

Inside Level 1 > Growth Hub > AI Video Projects, the panel displays the exact
owner-specific directory to use. The operator creates one project folder,
uploads each file with an `.uploading` suffix, and renames it to its final
video extension only when transfer completes. After the configured stability
window (60 seconds by default), the folder can be imported from the panel.

Each project folder is also its live production asset catalog:

```text
project-name/
├── source/          principal video(s), imported as editable projects
├── intros/
├── transitions/
├── b-roll/
├── images/
├── music/
├── sound-effects/
├── voice-over/
├── logos/
├── overlays/
├── outros/
└── exports/
```

Level 1 lists every supported file, its inferred role, type, size and readiness
without copying it into the database. Adding a file over SFTP makes it appear
in the project after refresh. Clicking an asset inserts its exact relative path
into the AI direction field; intro, outro, overlay, logo and audio selectors
also include compatible folder assets. OpenAI receives the safe project
catalog when preparing an edit plan, while the renderer resolves approved
private references directly from disk.

Imported database records contain a private `vnv-local://` reference, not a
public URL. Transcription extracts mono 16 kHz audio in 20-minute compressed
segments, and rendering reads the original directly from disk. Neither flow
loads the complete source video into PHP memory.

Provision at least four times the largest expected source size as free working
space. A 10 GB original therefore requires at least 40 GB free; 60–80 GB is a
safer operational floor when multiple renders can overlap. Limit worker
concurrency according to CPU, GPU, and disk throughput.
