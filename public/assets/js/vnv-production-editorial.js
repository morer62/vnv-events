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

    document.querySelectorAll('[data-vnv-watch-film]').forEach(link => {
        const film = document.getElementById(link.hash.slice(1));
        const player = film?.querySelector('video[controls]');
        if (!player) return;

        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (window.location.hash !== link.hash) history.pushState(null, '', link.hash);
            player.scrollIntoView({ block: 'center', behavior: 'instant' });
            player.focus({ preventScroll: true });
            player.play().catch(() => { /* Native controls remain available. */ });
        });
    });

    document.querySelectorAll('.ve-film video[controls]').forEach(player => {
        player.addEventListener('play', () => {
            document.querySelectorAll('[data-vnv-preview] video').forEach(preview => preview.pause());
        });
        const action = player.closest('.ve-film').querySelector('.vnv-film__copy .vnvp-button');
        action?.addEventListener('click', () => player.pause());
    });

    document.querySelectorAll('.ve-film__transcript').forEach(details => {
        const paragraph = details.querySelector('p');
        const captions = paragraph.querySelector('a');
        details.addEventListener('toggle', async () => {
            if (!details.open || !captions.isConnected || details.dataset.loading) return;
            details.dataset.loading = 'true';
            try {
                const response = await fetch(captions.href);
                if (!response.ok) return;
                const cues = (await response.text()).trim().split(/\r?\n\s*\r?\n/);
                const phrases = cues.flatMap(cue => {
                    const lines = cue.split(/\r?\n/);
                    const timing = lines.findIndex(line => line.includes(' --> '));
                    return timing < 0 ? [] : lines.slice(timing + 1);
                });
                if (phrases.length) paragraph.textContent = phrases.join(' ');
            } catch { /* Keep the direct link available when offline. */ }
            finally { delete details.dataset.loading; }
        });
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
