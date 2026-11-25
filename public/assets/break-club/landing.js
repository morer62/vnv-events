(() => {
    const size = document.getElementById('bc-party-size');
    const companion = document.getElementById('bc-companion');
    if (size && companion) {
        const updateParty = () => {
            const hasGuest = size.value === '2';
            companion.hidden = !hasGuest;
            companion.querySelector('[name="companion_name"]').required = hasGuest;
            companion.querySelectorAll('input').forEach(input => { input.disabled = !hasGuest; });
        };
        size.addEventListener('change', updateParty);
        window.addEventListener('pageshow', updateParty);
        updateParty();
    }
    const film = document.getElementById('club-film');
    const toggle = document.querySelector('.bc-film-toggle');
    if (!film || !toggle) return;
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const play = () => {
        if (!film.getAttribute('src')) {
            film.src = window.matchMedia('(max-width: 610px)').matches ? film.dataset.mobileSrc : film.dataset.src;
        }
        film.play().catch(() => { toggle.textContent = 'Play film'; });
    };
    toggle.hidden = false;
    toggle.addEventListener('click', () => { if (film.paused) play(); else film.pause(); });
    film.addEventListener('play', () => { toggle.textContent = 'Pause film'; });
    film.addEventListener('pause', () => { toggle.textContent = 'Play film'; });
    film.addEventListener('error', () => { toggle.hidden = true; });
    motion.addEventListener('change', e => { if (e.matches) film.pause(); });
    if (!motion.matches && !navigator.connection?.saveData) play();
})();
