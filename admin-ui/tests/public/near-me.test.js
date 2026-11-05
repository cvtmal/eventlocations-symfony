import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test runs it as a
// script. Google's library is faked: the real one needs a key and the network, which the owner's real-key run covers.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/near-me.js`, 'utf8');

const GEO_FORM = `
    <input id="nearby-ort" name="ort">
    <form data-near-me-geo data-error="Standort nicht verfügbar." data-pending="Standort wird ermittelt …" hidden>
        <input type="hidden" name="lat"><input type="hidden" name="lng">
        <button type="submit">Meinen Standort verwenden</button>
        <p data-near-me-geo-error role="status" hidden></p>
    </form>`;

const PINS = [
    { a: 47.2289, o: 8.6696, v: [{ n: '<b>x</b>', u: '/hochzeitslocation/x', p: 'Stäfa', k: '1 km' }] },
    { a: 47.3, o: 8.7, v: [{ n: 'A', u: '/a', p: 'Uster' }, { n: 'B', u: '/b', p: 'Uster' }, { n: 'C', u: '/c', p: 'Uster' }] },
];

function mapElement({ pins = PINS, centre = false } = {}) {
    const json = JSON.stringify(pins).replaceAll('"', '&quot;');
    const centreAttributes = centre ? ' data-centre-lat="47.23" data-centre-lng="8.67" data-radius="30000"' : '';

    return `
        <a data-near-me-skip href="#nearby-results">Karte überspringen</a>
        <div data-near-me-map data-maps-key="test-key" data-map-id="DEMO_MAP_ID" data-near-me-pins="${json}"${centreAttributes}
             data-label-you="Ihr Standort" data-label-group="__count__ Locations">
            <div data-near-me-canvas hidden></div>
            <p data-near-me-error>Die Karte kann gerade nicht angezeigt werden.</p>
        </div>`;
}

const mount = (html) => {
    document.head.innerHTML = '';
    document.body.innerHTML = html;
    new Function(SCRIPT)();
};

const googleScripts = () => document.head.querySelectorAll('script[src^="https://maps.googleapis.com/maps/api/js?"]');
const canvas = () => document.querySelector('[data-near-me-canvas]');
const error = () => document.querySelector('[data-near-me-error]');
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function fakeGoogle({ reject = false } = {}) {
    const made = { maps: [], markers: [], circles: [], infos: [], bounds: [] };
    class Map {
        constructor(element, options) {
            this.element = element;
            this.options = options;
            this.fitBounds = vi.fn();
            made.maps.push(this);
        }
    }
    class AdvancedMarkerElement {
        constructor(options) {
            this.options = options;
            this.listeners = {};
            made.markers.push(this);
        }

        addListener(name, listener) {
            this.listeners[name] = listener;
        }
    }
    class PinElement {
        constructor(options) {
            this.options = options;
            this.element = document.createElement('div');
        }
    }
    class Circle {
        constructor(options) {
            this.options = options;
            made.circles.push(this);
        }

        getBounds() {
            return 'circle-bounds';
        }
    }
    class InfoWindow {
        constructor() {
            this.setContent = vi.fn();
            this.open = vi.fn();
            made.infos.push(this);
        }
    }
    class LatLngBounds {
        constructor() {
            this.points = [];
            made.bounds.push(this);
        }

        extend(point) {
            this.points.push(point);
        }
    }
    const libraries = { core: { LatLngBounds }, maps: { Map, Circle, InfoWindow }, marker: { AdvancedMarkerElement, PinElement } };
    window.google = {
        maps: {
            importLibrary: vi.fn((name) => (reject ? Promise.reject(new Error('quota')) : Promise.resolve(libraries[name]))),
        },
    };

    return made;
}

async function loadGoogle(options) {
    const made = fakeGoogle(options);
    googleScripts()[0].dispatchEvent(new Event('load'));
    await flush();

    return made;
}

afterEach(() => {
    delete window.gm_authFailure;
    delete window.google;
    delete navigator.geolocation;
    vi.restoreAllMocks();
});

describe('geolocation button (#52)', () => {
    let submit;

    beforeEach(() => {
        submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    });

    const withGeolocation = (getCurrentPosition) => {
        Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } });
    };
    const form = () => document.querySelector('[data-near-me-geo]');
    const press = () => form().dispatchEvent(new Event('submit', { cancelable: true }));

    it('stays hidden without geolocation', () => {
        mount(GEO_FORM);

        expect(form().hidden).toBe(true);
    });

    it('rounds the position to two decimals and submits once', () => {
        withGeolocation((ok) => ok({ coords: { latitude: 47.37689, longitude: 8.54169 } }));
        mount(GEO_FORM);
        expect(form().hidden).toBe(false);

        press();

        expect(form().elements.lat.value).toBe('47.38');
        expect(form().elements.lng.value).toBe('8.54');
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('asks with a coarse position and never on load', () => {
        const getCurrentPosition = vi.fn();
        withGeolocation(getCurrentPosition);
        mount(GEO_FORM);
        expect(getCurrentPosition).not.toHaveBeenCalled();

        press();

        expect(getCurrentPosition.mock.calls[0][2]).toEqual({ enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
        expect(document.querySelector('[data-near-me-geo-error]').textContent).toBe('Standort wird ermittelt …');
        expect(document.querySelector('[data-near-me-geo] button').disabled).toBe(true);
    });

    it('shows the message and focuses the field when the position is refused', () => {
        withGeolocation((ok, fail) => fail({ code: 1 }));
        mount(GEO_FORM);

        press();

        const message = document.querySelector('[data-near-me-geo-error]');
        expect(message.hidden).toBe(false);
        expect(message.textContent).toBe('Standort nicht verfügbar.');
        expect(document.querySelector('[data-near-me-geo] button').disabled).toBe(false);
        expect(document.activeElement.id).toBe('nearby-ort');
        expect(submit).not.toHaveBeenCalled();
    });
});

describe('near-me map (#52)', () => {
    it('falls back when the pin data does not parse', () => {
        document.head.innerHTML = '';
        document.body.innerHTML = mapElement().replace(/data-near-me-pins="[^"]*"/, 'data-near-me-pins="{"');
        new Function(SCRIPT)();

        expect(canvas().hidden).toBe(true);
        expect(error().hidden).toBe(false);
        expect(googleScripts()).toHaveLength(0);
    });

    it('loads nothing from Google without a map element', () => {
        mount(GEO_FORM);

        expect(document.querySelectorAll('script')).toHaveLength(0);
        expect(window.gm_authFailure).toBeUndefined();
    });

    it('appends exactly one script with the documented parameters and swaps the line for the canvas', () => {
        mount(mapElement());

        expect(googleScripts()).toHaveLength(1);
        const url = new URL(googleScripts()[0].src);
        expect(Object.fromEntries(url.searchParams)).toEqual({ key: 'test-key', v: 'weekly', loading: 'async', language: 'de', region: 'CH' });
        expect(googleScripts()[0].async).toBe(true);
        expect(canvas().hidden).toBe(false);
        expect(error().hidden).toBe(true);
    });

    it('falls back to the line when the script fails', () => {
        mount(mapElement());

        googleScripts()[0].dispatchEvent(new Event('error'));

        expect(canvas().hidden).toBe(true);
        expect(error().hidden).toBe(false);
    });

    it('falls back when Google rejects the key', () => {
        mount(mapElement());

        window.gm_authFailure();

        expect(canvas().hidden).toBe(true);
        expect(error().hidden).toBe(false);
    });

    it('falls back when a library does not load', async () => {
        mount(mapElement());

        await loadGoogle({ reject: true });

        expect(canvas().hidden).toBe(true);
        expect(error().hidden).toBe(false);
    });

    it('draws one marker per pin and fits all of them without a centre', async () => {
        mount(mapElement());

        const made = await loadGoogle();

        expect(made.maps).toHaveLength(1);
        expect(made.maps[0].options.mapId).toBe('DEMO_MAP_ID');
        expect(made.markers).toHaveLength(2);
        expect(made.circles).toHaveLength(0);
        expect(made.bounds[0].points).toEqual([{ lat: 47.2289, lng: 8.6696 }, { lat: 47.3, lng: 8.7 }]);
        expect(made.maps[0].fitBounds).toHaveBeenCalledTimes(1);
        expect(made.markers[0].options.title).toBe('<b>x</b>');
        expect(made.markers[1].options.title).toBe('3 Locations');
        expect(error().hidden).toBe(true);
    });

    it('adds the visitor and a 30 km circle with a centre', async () => {
        mount(mapElement({ centre: true }));

        const made = await loadGoogle();

        expect(made.markers).toHaveLength(3);
        expect(made.markers[2].options.title).toBe('Ihr Standort');
        expect(made.circles).toHaveLength(1);
        expect(made.circles[0].options.radius).toBe(30000);
        expect(made.circles[0].options.center).toEqual({ lat: 47.23, lng: 8.67 });
        expect(made.maps[0].fitBounds).toHaveBeenCalledTimes(1);
        expect(made.maps[0].fitBounds).toHaveBeenCalledWith('circle-bounds');
    });

    it('fills the info window with text only', async () => {
        mount(mapElement());
        const made = await loadGoogle();

        made.markers[0].listeners['gmp-click']();

        const content = made.infos[0].setContent.mock.calls[0][0];
        expect(content.querySelector('b')).toBeNull();
        expect(content.querySelector('a').textContent).toBe('<b>x</b>');
        expect(content.querySelector('a').getAttribute('href')).toBe('/hochzeitslocation/x');
        expect(content.textContent).toContain('Stäfa · 1 km');
        expect(made.infos[0].open).toHaveBeenCalledWith({ map: made.maps[0], anchor: made.markers[0] });
    });
});
