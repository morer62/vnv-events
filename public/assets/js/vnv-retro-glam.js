document.querySelectorAll('[data-retro-edition-open]').forEach(opener => {
    const dialog = document.getElementById(opener.getAttribute('aria-controls'));
    if (!(dialog instanceof HTMLDialogElement)) return;

    opener.addEventListener('click', () => dialog.showModal());
    dialog.addEventListener('close', () => opener.focus({ preventScroll: true }));
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
});
