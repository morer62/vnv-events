document.querySelectorAll('.cb-module-card[aria-controls]').forEach(card => {
    const dialog = document.getElementById(card.getAttribute('aria-controls'));
    if (!dialog || typeof dialog.showModal !== 'function') return;

    card.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        dialog.showModal();
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const controls = Array.from(dialog.querySelectorAll('button, a[href]')).filter(control => control.getClientRects().length);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    dialog.addEventListener('close', () => card.focus({ preventScroll: true }));
});
