import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test
// runs it as a script. sendBeacon and IntersectionObserver are stubs; real delivery and real
// visibility stay browser-only.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/stats.js`, 'utf8');

let listeners;
let observers;
let beacon;

class Observer {
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.observed = new Set();
        observers.push(this);
    }

    observe(element) {
        this.observed.add(element);
    }

    unobserve(element) {
        this.observed.delete(element);
    }
}

function setNavigator(name, value) {
    Object.defineProperty(navigator, name, { value, configurable: true });
}

function page({ type = 'detail', venue = '46', cards = [], extra = '', readyState = 'loading' } = {}) {
    vi.spyOn(document, 'readyState', 'get').mockReturnValue(readyState);
    document.body.innerHTML = `
        ${cards.map((number) => `<article class="card" data-stats-venue="${number}"></article>`).join('')}
        ${extra}
        <script type="module" data-stats-url="/_stats" data-stats-page="${type}" data-stats-venue="${venue}"></script>`;
    new Function(SCRIPT)();
}

async function load() {
    window.dispatchEvent(new Event('load'));
    await vi.advanceTimersByTimeAsync(0);
}

// Every card is seen, as a stub cannot measure; a real observer would not report an unobserved one.
function intersect(numbers) {
    const [observer] = observers;
    const entries = numbers.map((number) => ({ isIntersecting: true, target: document.querySelector(`[data-stats-venue="${number}"]:not(script)`) }));
    observer.callback(entries, observer);
}

function hide() {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
}

function sent() {
    return beacon.mock.calls.map(([url, body]) => ({ url, p: body.get('p'), v: body.get('v'), w: body.get('w'), e: body.getAll('e[]') }));
}

describe('stats.js', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        listeners = [];
        observers = [];
        for (const target of [window, document]) {
            const add = target.addEventListener.bind(target);
            vi.spyOn(target, 'addEventListener').mockImplementation((type, listener, options) => {
                listeners.push([target, type, listener, options]);
                add(type, listener, options);
            });
        }
        beacon = vi.fn(() => true);
        setNavigator('sendBeacon', beacon);
        vi.stubGlobal('IntersectionObserver', Observer);
    });

    afterEach(() => {
        listeners.forEach(([target, type, listener, options]) => target.removeEventListener(type, listener, options));
        for (const name of ['sendBeacon', 'globalPrivacyControl', 'webdriver']) {
            delete navigator[name];
        }
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('sends nothing under Global Privacy Control', async () => {
        setNavigator('globalPrivacyControl', true);
        page({ cards: [7], extra: '<a href="https://x.example" data-stats-click="website"></a>' });

        await load();
        document.querySelector('[data-stats-click]').click();
        hide();

        expect(beacon).not.toHaveBeenCalled();
        expect(observers).toEqual([]);
    });

    it('sends the view once, after the load, with the page context', async () => {
        page();
        expect(beacon).not.toHaveBeenCalled();

        await load();
        hide();
        window.dispatchEvent(new Event('pagehide'));

        expect(sent()).toEqual([{ url: '/_stats', p: 'detail', v: '46', w: null, e: ['view'] }]);
        const body = beacon.mock.calls[0][1];
        expect(body.get('u')).toBe(location.pathname);
        expect(body.get('r')).toBe('');
    });

    it('sends the view at once on a page that has already loaded', async () => {
        page({ type: 'home', venue: '', readyState: 'complete' });

        await vi.advanceTimersByTimeAsync(0);

        expect(sent()).toEqual([{ url: '/_stats', p: 'home', v: '', w: null, e: ['view'] }]);
    });

    it('sends a contact click at once', async () => {
        page({ extra: '<a href="https://x.example" data-stats-click="website"><span>Website</span></a>' });
        await load();

        document.querySelector('[data-stats-click] span').click();

        expect(sent().map(({ e }) => e)).toEqual([['view'], ['click:website']]);
    });

    it('queues a Merkliste add until the tab is hidden', async () => {
        page({ type: 'listing', venue: '' });
        await load();

        document.dispatchEvent(new CustomEvent('merkliste:add', { detail: 46 }));
        expect(beacon).toHaveBeenCalledOnce();
        hide();

        expect(sent().map(({ e }) => e)).toEqual([['view'], ['add:46']]);
    });

    it('counts a card once per page view and stops observing it', async () => {
        page({ type: 'listing', venue: '', cards: [7, 8] });

        expect(observers[0].options).toEqual({ threshold: 0.5 });
        expect(observers[0].observed.size).toBe(2);
        intersect([7]);
        intersect([7, 8]);
        await load();

        expect(sent().map(({ e }) => e)).toEqual([['view', 'imp:7.8']]);
        expect(observers[0].observed.size).toBe(0);
    });

    it('sends 130 cards as two impressions of at most 100 numbers', async () => {
        const cards = Array.from({ length: 130 }, (_, index) => index + 1);
        page({ type: 'listing', venue: '', cards });

        intersect(cards);
        await load();

        const [events] = sent().map(({ e }) => e);
        expect(events).toEqual(['view', `imp:${cards.slice(0, 100).join('.')}`, `imp:${cards.slice(100).join('.')}`]);
    });

    it('marks an automated browser with w=1', async () => {
        setNavigator('webdriver', true);
        page();

        await load();

        expect(sent()[0].w).toBe('1');
    });
});
