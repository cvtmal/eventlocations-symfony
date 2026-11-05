import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test runs it as a
// script. Google's library and the element are faked: the real ones need a key and the network (the owner's real-key
// run covers them). IntersectionObserver is faked so a test decides when the box comes into view.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/google-rating.js`, 'utf8');

const BOX = `
    <div class="venue-google-rating venue-google-rating--live" data-google-rating data-maps-key="test-key" data-place-id="ChIJN1t_tDeuEmsRUsoyG83frY4" role="region" aria-label="Google-Bewertung">
        <a class="venue-google-rating__link" href="https://www.google.com/maps/search/?api=1" data-google-rating-link>Bewertungen auf Google Maps ansehen</a>
    </div>`;

let observers;
let readyState;

class FakeObserver {
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.targets = [];
        this.disconnect = vi.fn();
        observers.push(this);
    }

    observe(target) {
        this.targets.push(target);
    }
}

const mount = (html) => {
    document.head.innerHTML = '';
    document.body.innerHTML = html;
    new Function(SCRIPT)();
};

const googleScripts = () => document.head.querySelectorAll('script[src^="https://maps.googleapis.com/maps/api/js?"]');
const link = () => document.querySelector('[data-google-rating-link]');
const element = () => document.querySelector('gmp-place-details-compact');
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const pageLoaded = () => window.dispatchEvent(new Event('load'));
const intersect = (isIntersecting = true) => observers.at(-1).callback([{ isIntersecting }]);

function fakeGoogle({ reject = false } = {}) {
    window.google = {
        maps: {
            importLibrary: vi.fn(() => (reject ? Promise.reject(new Error('quota')) : Promise.resolve({}))),
        },
    };
}

// With `loading=async` Google calls the `callback` once the API is ready; the script's load event comes earlier.
async function loadGoogle(options) {
    fakeGoogle(options);
    window.googleRatingReady();
    await flush();
}

beforeEach(() => {
    observers = [];
    readyState = 'loading';
    vi.stubGlobal('IntersectionObserver', FakeObserver);
    vi.spyOn(document, 'readyState', 'get').mockImplementation(() => readyState);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete window.gm_authFailure;
    delete window.googleRatingReady;
    delete window.google;
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe('Google rating (#53)', () => {
    it('loads nothing without the box', () => {
        mount('<p>Keine Bewertung</p>');
        pageLoaded();

        expect(observers).toHaveLength(0);
        expect(googleScripts()).toHaveLength(0);
    });

    it('loads nothing without IntersectionObserver', () => {
        vi.stubGlobal('IntersectionObserver', undefined);
        mount(BOX);
        pageLoaded();

        expect(googleScripts()).toHaveLength(0);
    });

    it('waits for the page load event before it watches the box', () => {
        mount(BOX);

        expect(observers).toHaveLength(0);
        expect(googleScripts()).toHaveLength(0);

        pageLoaded();

        expect(observers).toHaveLength(1);
        expect(observers[0].targets).toEqual([document.querySelector('[data-google-rating]')]);
        expect(observers[0].options).toEqual({ rootMargin: '200px 0px' });
        expect(googleScripts()).toHaveLength(0);
    });

    it('watches at once when the page has already loaded', () => {
        readyState = 'complete';
        mount(BOX);

        expect(observers).toHaveLength(1);
    });

    it('waits for the box to come into view, then appends exactly one script with the documented parameters', () => {
        mount(BOX);
        pageLoaded();
        intersect(false);

        expect(googleScripts()).toHaveLength(0);

        intersect();
        expect(observers[0].disconnect).toHaveBeenCalled();

        const scripts = googleScripts();
        expect(scripts).toHaveLength(1);
        expect(scripts[0].async).toBe(true);
        const params = new URL(scripts[0].src).searchParams;
        expect(Object.fromEntries(params)).toEqual({
            key: 'test-key', v: 'weekly', loading: 'async', language: 'de', region: 'CH', callback: 'googleRatingReady',
        });
        expect(typeof window.googleRatingReady).toBe('function');
        expect(link().hidden).toBe(false);
        expect(element()).toBeNull();
    });

    it('builds nothing on the script load event alone', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        fakeGoogle();

        googleScripts()[0].dispatchEvent(new Event('load'));
        await flush();

        expect(window.google.maps.importLibrary).not.toHaveBeenCalled();
        expect(element()).toBeNull();
    });

    it('builds the compact element with the place and the name, rating and attribution', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        await loadGoogle();

        expect(window.google.maps.importLibrary).toHaveBeenCalledWith('places');
        expect(window.googleRatingReady).toBeUndefined();
        const compact = element();
        expect(compact).not.toBeNull();
        expect(compact.parentElement).toBe(document.querySelector('[data-google-rating]'));
        expect(compact.getAttribute('orientation')).toBe('horizontal');
        expect(compact.hasAttribute('truncation-preferred')).toBe(true);
        expect([...compact.children].map((child) => child.localName)).toEqual([
            'gmp-place-details-place-request',
            'gmp-place-name',
            'gmp-place-rating',
            'gmp-place-attribution',
        ]);
        expect(compact.querySelector('gmp-place-details-place-request').getAttribute('place')).toBe('ChIJN1t_tDeuEmsRUsoyG83frY4');
        const attribution = compact.querySelector('gmp-place-attribution');
        expect(attribution.getAttribute('light-scheme-color')).toBe('black');
        expect(attribution.getAttribute('dark-scheme-color')).toBe('white');
        expect(link().hidden).toBe(false);
    });

    it('hides the link once the element has loaded', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        await loadGoogle();

        element().dispatchEvent(new Event('gmp-load'));

        expect(link().hidden).toBe(true);
    });

    it('keeps the link and no element when the script fails', () => {
        mount(BOX);
        pageLoaded();
        intersect();

        googleScripts()[0].dispatchEvent(new Event('error'));

        expect(link().hidden).toBe(false);
        expect(element()).toBeNull();
    });

    it('removes the element and shows the link again when Google rejects the key', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        await loadGoogle();
        element().dispatchEvent(new Event('gmp-load'));

        window.gm_authFailure();

        expect(element()).toBeNull();
        expect(link().hidden).toBe(false);
    });

    it('builds nothing when the key was rejected before the library resolved', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        window.gm_authFailure();
        await loadGoogle();

        expect(element()).toBeNull();
        expect(link().hidden).toBe(false);
    });

    it('keeps the link and no element when the library does not load', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        await loadGoogle({ reject: true });

        expect(element()).toBeNull();
        expect(link().hidden).toBe(false);
    });

    it('removes the element when it reports an error', async () => {
        mount(BOX);
        pageLoaded();
        intersect();
        await loadGoogle();

        element().dispatchEvent(new Event('gmp-error'));

        expect(element()).toBeNull();
        expect(link().hidden).toBe(false);
    });
});
