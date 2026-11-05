// Google rating on the detail page (#53): when the server rendered the box (a key and a place ID are set), Google's
// compact Place Details element with the name, the stars and count, and Google's attribution. It starts only after
// the page's load event and once the box is near the viewport, so it never competes with the page's own content.
// Self-contained, no imports. Two globals, both Google's documented hooks (#52's stated deviation from "no globals"):
// `gm_authFailure`, and the `callback` the API calls once it is ready. With `loading=async` the script's own load event
// comes before `google.maps.importLibrary` exists (developers.google.com/maps/documentation/javascript/load-maps-js-api).
// Every failure (script, key, library, element) removes the element and leaves the link to Google Maps.
(() => {
    const box = document.querySelector('[data-google-rating]');
    if (!box || !window.IntersectionObserver) {
        return;
    }
    const link = box.querySelector('[data-google-rating-link]');
    let element = null;
    let failed = false;

    const fallback = () => {
        failed = true;
        element?.remove();
        element = null;
        link.hidden = false;
    };

    const make = (name, attributes = {}) => {
        const node = document.createElement(name);
        Object.entries(attributes).forEach(([attribute, value]) => node.setAttribute(attribute, value));

        return node;
    };

    // Elements and attributes as documented: developers.google.com/maps/documentation/javascript/places-ui-kit/place-details
    const build = async () => {
        await window.google.maps.importLibrary('places');
        if (failed) {
            return;
        }
        element = make('gmp-place-details-compact', { orientation: 'horizontal', 'truncation-preferred': '' });
        element.append(
            make('gmp-place-details-place-request', { place: box.dataset.placeId }),
            make('gmp-place-name'),
            make('gmp-place-rating'),
            make('gmp-place-attribution', { 'light-scheme-color': 'black', 'dark-scheme-color': 'white' }),
        );
        element.addEventListener('gmp-load', () => {
            if (!failed) {
                link.hidden = true;
            }
        });
        element.addEventListener('gmp-error', fallback);
        box.append(element);
    };

    const start = () => {
        window.gm_authFailure = fallback;
        window.googleRatingReady = () => {
            delete window.googleRatingReady;
            build().catch(fallback);
        };
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?${new URLSearchParams({
            key: box.dataset.mapsKey,
            v: 'weekly',
            loading: 'async',
            language: 'de',
            region: 'CH',
            callback: 'googleRatingReady',
        })}`;
        script.async = true;
        script.addEventListener('error', fallback);
        document.head.append(script);
    };

    const watch = () => {
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                observer.disconnect();
                start();
            }
        }, { rootMargin: '200px 0px' });
        observer.observe(box);
    };

    if (document.readyState === 'complete') {
        watch();
    } else {
        window.addEventListener('load', watch, { once: true });
    }
})();
