(() => {
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');

    document.querySelectorAll('[data-vnv-preview]').forEach(preview => {
        const video = preview.querySelector('video');
        const toggle = preview.querySelector('button');
        if (!video || !toggle) return;
        const mobile = window.matchMedia(`(max-width: ${preview.dataset.mobileWidth || 650}px)`);
        const playLabel = toggle.textContent.trim();
        const pauseLabel = toggle.dataset.pauseLabel || 'Pause preview';

        const play = () => {
            if (!video.getAttribute('src')) {
                video.src = mobile.matches ? video.dataset.mobileSrc : video.dataset.src;
            }
            video.play().catch(() => { toggle.textContent = playLabel; });
        };
        toggle.hidden = false;
        toggle.addEventListener('click', () => { if (video.paused) play(); else video.pause(); });
        video.addEventListener('play', () => { toggle.textContent = pauseLabel; });
        video.addEventListener('pause', () => { toggle.textContent = playLabel; });
        video.addEventListener('error', () => { toggle.hidden = true; });
        motion.addEventListener('change', event => { if (event.matches) video.pause(); });
        if (!motion.matches && !navigator.connection?.saveData) play();
    });

    document.querySelectorAll('.vnv-caption-frame').forEach(frame => {
        const video = frame.querySelector('video');
        const captions = frame.querySelector('.vnv-film-captions');
        const trackElement = video?.querySelector('track[kind="captions"][srclang="en"]');
        if (!captions || !trackElement?.track) return;

        const track = trackElement.track;
        const button = captions.querySelector('button');
        const text = captions.querySelector('.vnv-film-captions__text');
        let enabled = false;
        let iosFullscreen = false;
        const fullscreen = () => document.fullscreenElement === video || iosFullscreen;

        function render() {
            button.setAttribute('aria-pressed', String(enabled));
            text.hidden = !enabled || fullscreen();
            text.textContent = text.hidden ? '' : Array.from(track.activeCues || [], cue => cue.text).join('\n');
        }
        function updateMode() {
            // The inline rail and the full-screen renderer use the same timed track.
            if (enabled) track.mode = fullscreen() ? 'showing' : 'hidden';
            render();
        }
        button.addEventListener('click', () => {
            enabled = !enabled;
            if (!enabled) track.mode = 'disabled';
            updateMode();
        });
        track.addEventListener('cuechange', render);
        trackElement.addEventListener('load', render);
        video.textTracks.addEventListener('change', () => {
            if (track.mode === 'disabled' || (!fullscreen() && track.mode === 'showing')) enabled = false;
            render();
        });
        document.addEventListener('fullscreenchange', updateMode);
        video.addEventListener('webkitbeginfullscreen', () => { iosFullscreen = true; updateMode(); });
        video.addEventListener('webkitendfullscreen', () => { iosFullscreen = false; updateMode(); });
        captions.hidden = false;
    });

    document.querySelectorAll('[data-vnv-share-film]').forEach(button => {
        if (!navigator.share && !navigator.clipboard) return;
        const status = button.closest('.ve-film__tools').querySelector('[role="status"]');
        button.hidden = false;
        button.addEventListener('click', async () => {
            const url = new URL(button.dataset.vnvShareFilm, window.location.origin);
            status.textContent = '';
            try {
                if (navigator.share) await navigator.share({ title: button.dataset.filmTitle, url: url.href });
                else {
                    await navigator.clipboard.writeText(url.href);
                    status.textContent = 'Film link copied.';
                }
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = 'Open the film and copy its address to share it.';
            }
        });
    });
})();
