document.querySelectorAll('[data-retro-edition-open]').forEach(opener => {
    const dialog = document.getElementById(opener.getAttribute('aria-controls'));
    if (!dialog || typeof dialog.showModal !== 'function') return;

    opener.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        dialog.showModal();
    });
    dialog.addEventListener('close', () => opener.focus({ preventScroll: true }));
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
});
