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
