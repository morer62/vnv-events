function initMap() {
    const elements = document.querySelectorAll("[data-places]");

    elements.forEach(elm => {
        const autocomplete = new google.maps.places.Autocomplete(elm);

        autocomplete.addListener("place_changed", function () {
            const place = autocomplete.getPlace();

            if (place && place.geometry) {
                let latField, lngField;

                if (elm.id === 'autocomplete-address') {
                    latField = document.getElementById('lat');
                    lngField = document.getElementById('lng');
                } else {
                    latField = document.getElementById(elm.id.replace('address', 'lat').replace('autocomplete-', ''));
                    lngField = document.getElementById(elm.id.replace('address', 'lng').replace('autocomplete-', ''));
                }

                if (latField) latField.value = place.geometry.location.lat();
                if (lngField) lngField.value = place.geometry.location.lng();
            }

            if (place) {
                elm.value = place.formatted_address || place.name || elm.value;
                elm.dispatchEvent(new CustomEvent('vnv:place-selected', { detail: { place } }));
            }

            if (place && place.address_components && Array.isArray(place.address_components)) {
                const comps = place.address_components;
                const get = (types) => {
                    const found = comps.find(c => c.types && c.types.some(t => types.includes(t)));
                    return found ? (found.long_name || found.short_name || '') : '';
                };

                const city = get(['locality', 'postal_town', 'administrative_area_level_2', 'sublocality_level_1', 'sublocality']);
                const state = get(['administrative_area_level_1']);
                const zip = get(['postal_code', 'postal_code_prefix']);

                if (elm.id === 'payment-billing-address') {
                    const zipEl = document.getElementById('payment-billing-zip');
                    if (zipEl && zip) zipEl.value = zip.trim();
                }

                if (elm.id === 'billing_address_1' || elm.id === 'shipping_address_1') {
                    const prefix = elm.id.startsWith('billing_') ? 'billing' : 'shipping';
                    const cityEl = document.getElementById(prefix + '_city');
                    const stateEl = document.getElementById(prefix + '_state');
                    const zipEl = document.getElementById(prefix + '_zip');

                    if (cityEl && (city || place?.name)) cityEl.value = (city || place.name || '').trim();
                    if (stateEl && state) stateEl.value = state.trim();
                    if (zipEl && zip) zipEl.value = zip.trim();
                }

                if (elm.id === 'checkout_city') {
                    if (city) elm.value = city;
                }
            }
        });
    });
}
