// Near-me page (#52): the "Meinen Standort verwenden" button and, when the server rendered a map element (a key is
// set), the Google Maps JavaScript API map. Self-contained, no imports. The two globals are Google's documented
// `gm_authFailure` hook and the `callback` the API calls once it is ready (a stated deviation from "no globals"): with
// `loading=async` the script's own load event comes before `google.maps.importLibrary` exists
// (developers.google.com/maps/documentation/javascript/load-maps-js-api). Names and URLs reach the DOM through
// textContent only.
(() => {
    const geo = document.querySelector('form[data-near-me-geo]');
    if (geo && navigator.geolocation) {
        const button = geo.querySelector('button');
        const status = geo.querySelector('[data-near-me-geo-error]');
        const show = (text) => {
            status.textContent = text;
            status.hidden = false;
        };
        geo.hidden = false;
        geo.addEventListener('submit', (event) => {
            event.preventDefault();
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            show(geo.dataset.pending);
            navigator.geolocation.getCurrentPosition((position) => {
                geo.elements.lat.value = position.coords.latitude.toFixed(2);
                geo.elements.lng.value = position.coords.longitude.toFixed(2);
                // submit() fires no submit event, so this handler does not run again.
                geo.submit();
            }, () => {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                show(geo.dataset.error);
                document.getElementById('nearby-ort')?.focus();
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
        });
    }

    const element = document.querySelector('[data-near-me-map]');
    if (!element) {
        return;
    }
    // The box has its height from the first paint; the line it holds without JS swaps for the canvas, same height.
    const canvas = element.querySelector('[data-near-me-canvas]');
    const error = element.querySelector('[data-near-me-error]');
    const data = element.dataset;
    error.hidden = true;
    canvas.hidden = false;

    let failed = false;
    const fallback = () => {
        if (failed) {
            return;
        }
        failed = true;
        canvas.hidden = true;
        error.hidden = false;
    };

    let pins;
    try {
        pins = JSON.parse(data.nearMePins);
    } catch {
        fallback();

        return;
    }

    const centre = data.centreLat ? { lat: Number(data.centreLat), lng: Number(data.centreLng) } : null;

    // Built from DOM nodes: venue names are user data.
    const list = (venues) => {
        const ul = document.createElement('ul');
        ul.className = 'nearby-map__info';
        venues.forEach((venue) => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = venue.u;
            link.textContent = venue.n;
            li.append(link);
            const facts = [venue.p, venue.k].filter(Boolean).join(' · ');
            if (facts) {
                const small = document.createElement('span');
                small.textContent = facts;
                li.append(document.createElement('br'), small);
            }
            ul.append(li);
        });

        return ul;
    };

    const build = async () => {
        const maps = window.google.maps;
        const [{ LatLngBounds }, { Map, Circle, InfoWindow }, { AdvancedMarkerElement, PinElement }] = await Promise.all([
            maps.importLibrary('core'),
            maps.importLibrary('maps'),
            maps.importLibrary('marker'),
        ]);
        const tokens = getComputedStyle(document.documentElement);
        const accent = tokens.getPropertyValue('--c-accent-strong').trim();
        const onAccent = tokens.getPropertyValue('--c-on-accent').trim();
        const map = new Map(canvas, {
            mapId: data.mapId,
            center: centre ?? { lat: 46.8, lng: 8.23 },
            zoom: centre ? 10 : 7,
            gestureHandling: 'cooperative',
            streetViewControl: false,
            mapTypeControl: false,
        });
        const info = new InfoWindow();

        pins.forEach((pin) => {
            const count = pin.v.length;
            const marker = new AdvancedMarkerElement({
                map,
                position: { lat: pin.a, lng: pin.o },
                title: count > 1 ? data.labelGroup.replace('__count__', String(count)) : pin.v[0].n,
                gmpClickable: true,
                content: new PinElement({
                    background: accent,
                    borderColor: accent,
                    glyphColor: onAccent,
                    glyph: count > 1 ? String(count) : undefined,
                }).element,
            });
            marker.addListener('gmp-click', () => {
                info.setContent(list(pin.v));
                info.open({ map, anchor: marker });
            });
        });

        if (centre) {
            const dot = document.createElement('div');
            dot.className = 'nearby-map__you';
            new AdvancedMarkerElement({ map, position: centre, title: data.labelYou, content: dot });
            const circle = new Circle({
                map,
                center: centre,
                radius: Number(data.radius),
                clickable: false,
                strokeColor: accent,
                strokeOpacity: 0.6,
                strokeWeight: 2,
                fillColor: accent,
                fillOpacity: 0.12,
            });
            map.fitBounds(circle.getBounds());
        } else if (pins.length > 0) {
            const bounds = new LatLngBounds();
            pins.forEach((pin) => bounds.extend({ lat: pin.a, lng: pin.o }));
            map.fitBounds(bounds);
        }
    };

    window.gm_authFailure = fallback;
    window.nearMeMapReady = () => {
        delete window.nearMeMapReady;
        build().catch(fallback);
    };
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?${new URLSearchParams({
        key: data.mapsKey,
        v: 'weekly',
        loading: 'async',
        language: 'de',
        region: 'CH',
        callback: 'nearMeMapReady',
    })}`;
    script.async = true;
    script.addEventListener('error', fallback);
    document.head.append(script);
})();
