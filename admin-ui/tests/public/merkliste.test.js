import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test
// runs it as a script. Real navigation, bfcache and location.reload() stay browser-only; another
// tab or document of the same origin is simulated by writing the shared cookie.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/merkliste.js`, 'utf8');
const NAME = 'merkliste';
const SYNC_DELAY_MS = 3000;
const MAX_ENTRIES = 100;
const ONE_YEAR_S = 34560000;

let listeners;
let flights;

function setCookie(wire) {
    document.cookie = `${NAME}=${wire}; Max-Age=${wire === '' ? 0 : ONE_YEAR_S}; Path=/`;
}

function cookie() {
    const entry = document.cookie.split('; ').find((pair) => pair.startsWith(`${NAME}=`));

    return entry ? entry.slice(NAME.length + 1) : null;
}

// Like POST /merkliste: echoes the list of the request, however old it is by the time it lands.
function server() {
    return vi.fn(() => {
        const sent = cookie();

        return new Promise((resolve) => {
            flights.push({
                land: () => {
                    setCookie(sent.replace(/%2C$/, ''));
                    resolve({ ok: true });
                },
                dropSetCookie: () => resolve({ ok: true }),
            });
        });
    });
}

async function land(how = 'land') {
    flights.shift()[how]();
    await vi.advanceTimersByTimeAsync(0);
}

const after = (ms) => vi.advanceTimersByTimeAsync(ms);

function page(numbers = [15, 1, 38], extra = '') {
    document.body.innerHTML = `
        <a data-merkliste-link href="/merkliste" data-sync-token="csrf-token"
            data-toast-added="__name__ +" data-toast-removed="__name__ -"
            data-toast-full="voll" data-toast-link="Zur Merkliste"><svg class="icon"></svg><span data-merkliste-count hidden></span></a>
        ${numbers.map((number) => `<form><button data-merken="${number}" data-merken-name="V${number}"></button></form>`).join('')}
        ${extra}
        <div data-toast-region></div>`;
    new Function(SCRIPT)();
}

function merklistePage(numbers, promoted = []) {
    document.body.innerHTML = `
        <a data-merkliste-link href="/merkliste" data-sync-token="csrf-token"
            data-toast-removed="__name__ -" data-toast-link="Zur Merkliste"><svg class="icon"></svg><span data-merkliste-count hidden></span></a>
        <div data-merkliste-page>
            <h1 tabindex="-1" data-merkliste-title></h1>
            <p data-merkliste-total data-count-one="1 Location" data-count-other="__count__ Locations"></p>
            <p data-merkliste-full hidden></p>
            <ol>${numbers.map((number) => `<li data-merkliste-item>${promoted.includes(number) ? '<span class="badge">Top-Eintrag</span>' : ''}<form><button data-merkliste-remove="${number}" data-merken-name="V${number}"></button></form></li>`).join('')}</ol>
            ${promoted.length > 0 ? '<p class="promoted-note" data-merkliste-promoted-note></p>' : ''}
            <div data-merkliste-empty hidden></div>
        </div>
        <div data-toast-region></div>`;
    new Function(SCRIPT)();
}

function heart(number) {
    document.querySelector(`[data-merken="${number}"]`).form.requestSubmit();
}

function pressed(number) {
    return document.querySelector(`[data-merken="${number}"]`).getAttribute('aria-pressed');
}

// A jsdom event's timeStamp ignores fake timers, so the guard's clock is set by hand.
function submitAt(form, timeStamp) {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'timeStamp', { value: timeStamp });
    form.dispatchEvent(event);

    return event;
}

function hide() {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
}

function show() {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
}

describe('merkliste.js', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        listeners = [];
        flights = [];
        for (const target of [window, document]) {
            const add = target.addEventListener.bind(target);
            vi.spyOn(target, 'addEventListener').mockImplementation((type, listener, options) => {
                listeners.push([target, type, listener]);
                add(type, listener, options);
            });
        }
        vi.stubGlobal('fetch', server());
    });

    afterEach(() => {
        listeners.forEach(([target, type, listener]) => target.removeEventListener(type, listener));
        setCookie('');
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    describe('the marker', () => {
        it('marks a script write and lets the server drop the marker, once per burst', async () => {
            page();

            heart(15);
            await after(500);
            heart(1);

            expect(cookie()).toBe('15%2C1%2C');
            await after(SYNC_DELAY_MS - 1);
            expect(fetch).not.toHaveBeenCalled();
            await after(1);
            expect(fetch).toHaveBeenCalledOnce();
            const [url, init] = fetch.mock.calls[0];
            expect(url).toBe(document.querySelector('[data-merkliste-link]').href);
            expect(init.method).toBe('POST');
            expect(init.body.toString()).toBe('_token=csrf-token');
            expect(init.keepalive).toBe(false);

            await land();
            await after(10 * SYNC_DELAY_MS);

            expect(cookie()).toBe('15%2C1');
            expect(fetch).toHaveBeenCalledOnce();
        });

        it('retries a list left marked by an earlier page, 3 s after the load', async () => {
            setCookie('15%2C');
            page();

            expect(fetch).not.toHaveBeenCalled();
            await after(SYNC_DELAY_MS);

            expect(fetch).toHaveBeenCalledOnce();
        });
    });

    describe('the no-request rule', () => {
        it.each([
            ['no cookie', ''],
            ['a clean list', '1%2C2'],
            ['a marked value with no entry', 'abc%2C'],
            ['only the marker', '%2C'],
        ])('sends nothing for %s', async (_, wire) => {
            setCookie(wire);
            page();

            await after(2 * SYNC_DELAY_MS);
            hide();
            show();
            window.dispatchEvent(new Event('focus'));
            window.dispatchEvent(new Event('pagehide'));
            await after(2 * SYNC_DELAY_MS);

            expect(fetch).not.toHaveBeenCalled();
        });

        it('sends nothing when the last entry goes: the cookie is deleted', async () => {
            setCookie('15');
            page();

            heart(15);
            await after(2 * SYNC_DELAY_MS);

            expect(cookie()).toBeNull();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('the landed guard', () => {
        it('does not retry an answer that left the marker until this tab writes again', async () => {
            page();

            heart(15);
            await after(SYNC_DELAY_MS);
            await land('dropSetCookie');
            await after(10 * SYNC_DELAY_MS);

            expect(cookie()).toBe('15%2C');
            expect(fetch).toHaveBeenCalledOnce();

            heart(1);
            await after(SYNC_DELAY_MS);

            expect(fetch).toHaveBeenCalledTimes(2);
        });
    });

    describe('the journal', () => {
        it('keeps a heart added during a flight, although the answer overwrites it', async () => {
            page();

            heart(15);
            await after(SYNC_DELAY_MS);
            heart(1);
            await land();

            expect(cookie()).toBe('15%2C1%2C');
        });

        it('keeps a removal made during a flight, although the answer brings the entry back', async () => {
            page();

            heart(15);
            heart(1);
            await after(SYNC_DELAY_MS);
            heart(15);
            await land();

            expect(cookie()).toBe('1%2C');
        });

        it('re-asserts an addition another tab\'s answer dropped', async () => {
            page();

            heart(15);
            setCookie('1');
            await after(SYNC_DELAY_MS);

            expect(cookie()).toBe('1%2C15%2C');
        });

        it('does not outlive leaving the page: a removal in the next document stays', async () => {
            page();

            heart(15);
            window.dispatchEvent(new Event('pagehide'));
            expect(fetch).toHaveBeenCalledOnce();

            // The answer's cookie arrived while the page sat in the bfcache and the tab unsaved the
            // venue on a detail page. The page only sees the landing on its way back.
            setCookie('');
            await land('dropSetCookie');
            await after(2 * SYNC_DELAY_MS);

            expect(cookie()).toBeNull();
            expect(fetch).toHaveBeenCalledOnce();
        });

        it('does not outlive the landing: a removal in another tab much later stays', async () => {
            page();

            heart(15);
            await after(SYNC_DELAY_MS);
            await land();

            // Another tab's heart: this tab's next check sees that tab's marker.
            setCookie('15%2C1%2C');
            await after(SYNC_DELAY_MS);
            setCookie('15%2C1');

            await after(30000);
            setCookie('1');
            heart(38);
            await after(SYNC_DELAY_MS);
            await land();
            await after(SYNC_DELAY_MS);

            expect(cookie()).toBe('1%2C38');
        });

        it('ends at a clean landing: a removal in another tab much later stays', async () => {
            page();

            heart(15);
            heart(1);
            await after(SYNC_DELAY_MS);
            await land();
            await after(SYNC_DELAY_MS);

            // Another tab removed 15 and its sync has landed.
            setCookie('1');
            await after(30000);
            heart(38);
            await after(SYNC_DELAY_MS);
            await land();
            await after(SYNC_DELAY_MS);

            expect(cookie()).toBe('1%2C38');
        });
    });

    describe('leaving', () => {
        it('sends one keepalive when the tab is hidden, and only then', async () => {
            page();

            heart(15);
            hide();

            expect(fetch).toHaveBeenCalledOnce();
            expect(fetch.mock.calls[0][1].keepalive).toBe(true);
            expect(fetch.mock.calls[0][1].signal).toBeUndefined();

            window.dispatchEvent(new Event('pagehide'));

            expect(fetch).toHaveBeenCalledOnce();
        });

        it('sends a keepalive on pagehide for browsers that skip the hidden state', () => {
            page();

            heart(15);
            window.dispatchEvent(new Event('pagehide'));

            expect(fetch.mock.calls[0][1].keepalive).toBe(true);
        });
    });

    describe('the removal guard', () => {
        it('removes one venue for a double-click and the next on a deliberate click', () => {
            setCookie('1%2C2%2C3');
            merklistePage([1, 2, 3]);
            const [first, second, third] = document.querySelectorAll('[data-merkliste-item] form');

            expect(submitAt(first, 1000).defaultPrevented).toBe(true);
            expect(submitAt(second, 1120).defaultPrevented).toBe(true);

            expect(cookie()).toBe('2%2C3%2C');
            expect(document.querySelectorAll('[data-merkliste-item]')).toHaveLength(2);

            submitAt(second, 1600);
            expect(cookie()).toBe('3%2C');

            submitAt(third, 2100);
            expect(cookie()).toBeNull();
            expect(document.querySelector('[data-merkliste-empty]').hidden).toBe(false);
        });

        it('ignores a held Enter on a remove button and only there', () => {
            setCookie('1%2C2');
            merklistePage([1, 2]);
            const button = document.querySelector('[data-merkliste-remove]');
            const press = (target, repeat) => {
                const event = new KeyboardEvent('keydown', { key: 'Enter', repeat, bubbles: true, cancelable: true });
                target.dispatchEvent(event);

                return event.defaultPrevented;
            };

            expect(press(button, true)).toBe(true);
            expect(press(button, false)).toBe(false);
            expect(press(document.querySelector('[data-merkliste-link]'), true)).toBe(false);
        });
    });

    describe('the Top-Eintrag footnote', () => {
        it('stays while a promoted card is left and goes with the last one', () => {
            setCookie('1%2C2%2C3');
            merklistePage([1, 2, 3], [1, 3]);
            const note = document.querySelector('[data-merkliste-promoted-note]');
            const [first, second, third] = document.querySelectorAll('[data-merkliste-item] form');

            submitAt(first, 1000);
            expect(note.hidden).toBe(false);

            submitAt(third, 2000);
            expect(note.hidden).toBe(true);
            expect(document.querySelectorAll('[data-merkliste-item]')).toHaveLength(1);
            expect(second.isConnected).toBe(true);
        });

        it('goes when the last card goes', () => {
            setCookie('1%2C');
            merklistePage([1], [1]);

            submitAt(document.querySelector('[data-merkliste-item] form'), 1000);

            expect(document.querySelector('[data-merkliste-promoted-note]').hidden).toBe(true);
        });
    });

    describe('the badges', () => {
        it('fills and hides the header\'s and the menu\'s badge together', () => {
            page([15], '<a href="/merkliste"><span data-merkliste-count hidden></span></a>');
            const badges = [...document.querySelectorAll('[data-merkliste-count]')];
            expect(badges).toHaveLength(2);

            heart(15);
            expect(badges.map((badge) => badge.textContent)).toEqual(['1', '1']);
            expect(badges.map((badge) => badge.hidden)).toEqual([false, false]);

            heart(15);
            expect(badges.map((badge) => badge.hidden)).toEqual([true, true]);
        });
    });

    describe('the toast', () => {
        const parts = () => [...document.querySelector('[data-toast-region] .toast').children].map((part) => part.getAttribute('class'));

        it('holds the header\'s heart, the text and the link for an added venue', () => {
            page([15]);

            heart(15);

            expect(parts()).toEqual(['icon', 'toast__text', 'toast__link']);
            expect(document.querySelector('.toast__text').textContent).toBe('V15 +');
            expect(document.querySelector('.toast__link').textContent).toBe('Zur Merkliste');
            expect(document.querySelector('.toast__link').pathname).toBe('/merkliste');
        });

        it('keeps the link when a venue is removed off /merkliste', () => {
            setCookie('15');
            page([15]);

            heart(15);

            expect(parts()).toEqual(['icon', 'toast__text', 'toast__link']);
            expect(document.querySelector('.toast__text').textContent).toBe('V15 -');
        });

        it('has no link when a venue is removed on /merkliste, which it would point at', () => {
            setCookie('1%2C2');
            merklistePage([1, 2]);

            submitAt(document.querySelector('[data-merkliste-item] form'), 1000);

            expect(parts()).toEqual(['icon', 'toast__text']);
            expect(document.querySelector('.toast__text').textContent).toBe('V1 -');
        });

        it('holds the heart, the text and the link when the list is full', () => {
            setCookie(encodeURIComponent(Array.from({ length: MAX_ENTRIES }, (_, index) => index + 1).join()));
            page([500]);

            heart(500);

            expect(parts()).toEqual(['icon', 'toast__text', 'toast__link']);
            expect(document.querySelector('.toast__text').textContent).toBe('voll');
        });
    });

    describe('the add event', () => {
        // The spied addEventListener records the listener, so afterEach removes it.
        function added() {
            const numbers = [];
            document.addEventListener('merkliste:add', (event) => numbers.push(event.detail));

            return numbers;
        }

        it('tells stats.js the number of an added venue', () => {
            page([15]);
            const numbers = added();

            heart(15);

            expect(numbers).toEqual([15]);
        });

        it.each([
            ['a removal', '15', 15],
            ['a refused add on a full list', encodeURIComponent(Array.from({ length: MAX_ENTRIES }, (_, index) => index + 1).join()), 500],
        ])('stays silent for %s', (_, wire, number) => {
            setCookie(wire);
            page([number]);
            const numbers = added();

            heart(number);

            expect(numbers).toEqual([]);
        });
    });

    describe('a stale window', () => {
        it('shows the current list on focus, without a request', async () => {
            page();
            expect(pressed(15)).toBe('false');

            setCookie('15');
            window.dispatchEvent(new Event('focus'));
            await after(2 * SYNC_DELAY_MS);

            expect(pressed(15)).toBe('true');
            expect(document.querySelector('[data-merkliste-count]').textContent).toBe('1');
            expect(fetch).not.toHaveBeenCalled();
        });
    });
});
