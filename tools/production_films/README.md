# VNV commercial film editing sources

This package preserves the eight-film commercial edition dated November 27, 2026. The previous masters are recoverable from Git revision `48045370`. The website uses stable asset IDs. In particular, `cuebook` is the asset ID for the film publicly branded **VNV-Q**.

- [Editorial source](edition.json) contains eight complete English reads, beat-by-beat scene boundaries, public graphics for the six shared layouts and the selected motion rushes.
- [Editable compositions and original voice takes](editing-sources.zip) contains the six-film renderer, dedicated Retro Glam and VNV-Q scenes, both brand marks, music generation for Retro, the eight uncut English MP3 takes and their recorded word boundaries.
- [Editorial brief](../../docs/VNV_PRODUCTION_FILM_BRIEF.md) defines the message, caption and QA standard. Current MP4s, timed English captions and player posters are under `public/assets/video/production-films/v8/`.

## Rebuilding a film

Extract the source archive into a working directory. Use Python with Pillow, NumPy and edge-tts, FFmpeg and FFprobe. The existing font set uses Segoe UI, Consolas, Georgia, Bodoni and Montserrat on Windows. Set the following environment variables to authorized local paths.

| Variable | Contents |
| --- | --- |
| `VNV_FILM_LIBRARY` | VNV Hub Film source library, including Corporate and Activation rushes, the stage design motion, and approved score stems under Service films v8 |
| `VNV_VIDEO_ASSETS` | VNV's `public/assets/video` directory, with the two original event montages. The neighboring `videos` directory holds the approved culinary footage |
| `VNV_BREAK_RUSHES` | Break Club live-action motion source directory |
| `VNV_FILM_OUTPUT` | Target directory for the MP4s, WebP posters and English WebVTT tracks. The software workflow also reads the older v5-corporate and v5-activation preview clips from adjacent directories |

Run `python continuous.py` in the extracted directory to generate each clean WAV, timings and wrapped WebVTT from the original English takes. Cached audio and timing metadata allow an exact reconstruction. A changed script requires a new corresponding take and new word metadata. Then render the selected film.

```text
python render.py full-production corporate-events cause-on-cue brand-activation original-experiences morning-club
python Retro/retro-edit.py
python VNV-Q/finish.py
```

Use the authorized VNV source library for the motion rushes and score stems. The studio library also retains the finished compositor files, production notes, complete voice masters and final review sheets under `Service films v9`. The Git source package keeps the reusable graphics and original narration small enough to version alongside the release.
