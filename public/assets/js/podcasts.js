(function () {
    const modal = document.getElementById('podcastEpisodeModal');
    if (!modal) return;

    const iframe = modal.querySelector('iframe');
    const title = modal.querySelector('[data-podcast-title]');
    const description = modal.querySelector('[data-podcast-description]');
    const credit = modal.querySelector('[data-podcast-credit]');
    const source = modal.querySelector('[data-podcast-source]');
    const closeButton = modal.querySelector('.podcast-modal__close');
    let lastFocus = null;

    function focusable() {
        return Array.from(modal.querySelectorAll('button:not([disabled]), a[href], iframe'));
    }

    function open(trigger) {
        const id = trigger.dataset.videoId;
        if (!/^[A-Za-z0-9_-]{11}$/.test(id || '')) return;
        lastFocus = document.activeElement;
        title.textContent = trigger.dataset.videoTitle || '';
        description.textContent = trigger.dataset.videoDescription || '';
        credit.textContent = trigger.dataset.videoCredit || '';
        source.href = trigger.dataset.videoSource || '#';
        iframe.title = `YouTube video: ${trigger.dataset.videoTitle || 'Podcast episode'}`;
        iframe.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1`;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('podcast-modal-lock');
        window.setTimeout(() => closeButton.focus(), 20);
    }

    function close() {
        iframe.src = 'about:blank';
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('podcast-modal-lock');
        if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
        lastFocus = null;
    }

    document.querySelectorAll('[data-podcast-open]').forEach((trigger) => {
        trigger.addEventListener('click', () => open(trigger));
    });
    modal.querySelectorAll('[data-podcast-close]').forEach((trigger) => {
        trigger.addEventListener('click', close);
    });
    document.addEventListener('keydown', (event) => {
        if (!modal.classList.contains('is-open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const elements = focusable();
        if (!elements.length) return;
        const first = elements[0];
        const last = elements[elements.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
