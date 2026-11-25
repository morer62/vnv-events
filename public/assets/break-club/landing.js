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
})();
