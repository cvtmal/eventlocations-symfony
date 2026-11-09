// Merkliste (#13, S7): the saved state on cached pages, which never read the cookie (S10). Same format
// and read rule as App\Favorites\ValueObject\MerklisteCookie. Self-contained, no globals, no innerHTML.
//
// WebKit caps a cookie written here at 7 days, not one set by a response, so POST /merkliste re-issues
// each list (MK-2). A trailing comma marks a list not re-issued yet; only such a list sends a request.
// An answer can overwrite a newer write, so a tab re-asserts its journal until the cookie stays clean.
(() => {
    const NAME = 'merkliste';
    const MAX_ENTRIES = 100;
    const MAX_NUMBER = 2147483647;
    const MAX_AGE_SECONDS = 34560000;
    const TOAST_MS = 6000;
    // SYNC_TIMEOUT_MS + 1000 <= SYNC_DELAY_MS: a flight ends before another tab re-asserts and syncs,
    // also under the 1 s clamp on hidden-tab timers.
    const SYNC_DELAY_MS = 3000;
    const SYNC_TIMEOUT_MS = 2000;
    const REMOVE_GUARD_MS = 450;

    const link = document.querySelector('[data-merkliste-link]');
    if (!link) {
        return;
    }
    const badges = document.querySelectorAll('[data-merkliste-count]');

    const value = () => {
        const prefix = NAME + '=';
        const entry = document.cookie.split('; ').find((cookie) => cookie.startsWith(prefix));
        try {
            return decodeURIComponent((entry || prefix).slice(prefix.length));
        } catch {
            return '';
        }
    };

    const read = () => {
        const numbers = [];
        for (const token of value().split(',')) {
            const number = Number(token);
            if (numbers.length < MAX_ENTRIES && /^[1-9]\d{0,9}$/.test(token) && number <= MAX_NUMBER && !numbers.includes(number)) {
                numbers.push(number);
            }
        }

        return numbers;
    };

    // The server writes nothing for a list with no entry.
    const dirty = () => value().endsWith(',') && read().length > 0;

    let timer;
    let flight = null;
    let seq = 0;
    let landed = -1;
    let flushed = -1;
    const journal = new Map();

    const write = (numbers) => {
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        const maxAge = numbers.length > 0 ? MAX_AGE_SECONDS : 0;
        const marked = numbers.length > 0 ? `${numbers.join(',')},` : '';
        document.cookie = `${NAME}=${encodeURIComponent(marked)}; Max-Age=${maxAge}; Path=/; SameSite=Lax${secure}`;
        seq += 1;
    };

    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(sync, SYNC_DELAY_MS);
    };

    const change = (numbers, number, saved) => {
        write(numbers);
        journal.set(number, { saved, at: seq });
        schedule();
    };

    // Additions always, removals only if newer than the landed snapshot: a returned entry is one
    // click to fix, a lost one is silent.
    const reconcile = (since = Infinity) => {
        const numbers = read();
        const next = numbers.filter((number) => {
            const entry = journal.get(number);

            return !entry || entry.saved || entry.at <= since;
        });
        journal.forEach(({ saved }, number) => {
            if (saved && !next.includes(number) && next.length < MAX_ENTRIES) {
                next.push(number);
            }
        });
        if (next.join() !== numbers.join()) {
            write(next);
        }
    };

    // Failures stay silent; the marker makes the next page view retry. A keepalive flight cannot be
    // aborted, so only the flush on leaving is one.
    const sync = (keepalive = false) => {
        clearTimeout(timer);
        flight?.abort();
        reconcile();
        if (!dirty()) {
            journal.clear();

            return;
        }
        // The answer left the marker and nothing was written since: a retry would loop.
        if (landed === seq) {
            journal.clear();

            return;
        }

        const sent = seq;
        const controller = keepalive ? null : new AbortController();
        const deadline = controller && setTimeout(() => controller.abort(), SYNC_TIMEOUT_MS);
        flight = controller;
        fetch(link.href, { method: 'POST', body: new URLSearchParams({ _token: link.dataset.syncToken }), keepalive, signal: controller?.signal })
            .then((response) => {
                if (response.ok) {
                    landed = sent;
                    reconcile(sent);
                    schedule();
                }
            })
            .catch(() => {})
            .finally(() => clearTimeout(deadline));
    };

    const flush = () => {
        flight?.abort();
        if (flushed !== seq) {
            sync(true);
            flushed = seq;
        }
    };

    const render = () => {
        const numbers = read();
        document.querySelectorAll('[data-merken]').forEach((button) => {
            button.setAttribute('aria-pressed', String(numbers.includes(Number(button.dataset.merken))));
        });
        badges.forEach((badge) => {
            badge.textContent = String(numbers.length);
            badge.hidden = numbers.length === 0;
        });
    };

    let toastTimer;
    const toast = (message, withLink) => {
        const node = document.createElement('div');
        node.className = 'toast';
        node.dataset.toast = '';
        const text = document.createElement('span');
        text.className = 'toast__text';
        text.textContent = message;
        // Cloned, because the CSP forbids a data URI and the header's heart is on every page.
        node.append(link.querySelector('.icon').cloneNode(true), text);
        if (withLink) {
            const anchor = document.createElement('a');
            anchor.className = 'toast__link';
            anchor.href = link.href;
            anchor.textContent = link.dataset.toastLink;
            node.append(anchor);
        }
        clearTimeout(toastTimer);
        document.querySelector('[data-toast-region]').replaceChildren(node);
        // A link that vanished under the pointer or the focus would drop keyboard users on <body>.
        const dismiss = () => {
            if (node.matches(':hover, :focus-within')) {
                toastTimer = setTimeout(dismiss, TOAST_MS);
            } else {
                node.remove();
            }
        };
        toastTimer = setTimeout(dismiss, TOAST_MS);
    };

    // A replacer function, so that a "$" in a venue name stays literal.
    const withName = (template, name) => template.replace('__name__', () => name);

    const toggle = (button) => {
        const number = Number(button.dataset.merken);
        const name = button.dataset.merkenName;
        const numbers = read();

        if (numbers.includes(number)) {
            change(numbers.filter((saved) => saved !== number), number, false);
            render();
            toast(withName(link.dataset.toastRemoved, name), true);
        } else if (numbers.length >= MAX_ENTRIES) {
            // Refused, not evicted: the oldest entry is the one a couple saved first.
            toast(link.dataset.toastFull, true);
        } else {
            change([...numbers, number], number, true);
            // For stats.js (#47). A DOM event, so Favorites knows nothing of Stats.
            document.dispatchEvent(new CustomEvent('merkliste:add', { detail: number }));
            render();
            toast(withName(link.dataset.toastAdded, name), true);
        }
    };

    const updatePage = () => {
        const left = document.querySelectorAll('[data-merkliste-item]').length;
        const total = document.querySelector('[data-merkliste-total]');
        total.textContent = left === 1 ? total.dataset.countOne : total.dataset.countOther.replace('__count__', () => String(left));
        total.hidden = left === 0;
        document.querySelector('[data-merkliste-full]').hidden = read().length < MAX_ENTRIES;
        document.querySelector('[data-merkliste-empty]').hidden = left > 0;
        // The «Top-Eintrag» footnote explains a badge; it goes with the last promoted card (G13). Not `.badge`: «Kurzfristig frei» (G14) is one too.
        const note = document.querySelector('[data-merkliste-promoted-note]');
        if (note) {
            note.hidden = !document.querySelector('[data-merkliste-item][data-merkliste-promoted]');
        }
    };

    // In place, because a reload would jump to the top of a list of up to 100 cards.
    const remove = (button) => {
        const number = Number(button.dataset.merklisteRemove);
        const item = button.closest('[data-merkliste-item]');
        const neighbour = item.nextElementSibling || item.previousElementSibling;
        const next = neighbour ? neighbour.querySelector('[data-merkliste-remove]') : document.querySelector('[data-merkliste-title]');

        change(read().filter((saved) => saved !== number), number, false);
        if (!neighbour) {
            item.parentElement.remove();
        } else {
            item.remove();
        }
        updatePage();
        render();
        toast(withName(link.dataset.toastRemoved, button.dataset.merkenName), false);
        next.focus();
    };

    // Never at load, so a reload cannot loop.
    const refresh = () => {
        render();
        const rendered = [...document.querySelectorAll('[data-merkliste-remove]')].map((button) => button.dataset.merklisteRemove);
        if (document.querySelector('[data-merkliste-page]') && read().join() !== rendered.join()) {
            location.reload();
        }
    };

    let lastRemoval = -Infinity;
    document.addEventListener('submit', (event) => {
        const merken = event.target.querySelector('[data-merken]');
        const removal = event.target.querySelector('[data-merkliste-remove]');
        if (merken) {
            event.preventDefault();
            toggle(merken);
        } else if (removal) {
            event.preventDefault();
            // The next card slides under the pointer and there is no undo. Measured from the last
            // removal, so slow clicks keep working.
            if (event.timeStamp - lastRemoval >= REMOVE_GUARD_MS) {
                lastRemoval = event.timeStamp;
                remove(removal);
            }
        }
    });

    // Focus has moved to the next button, and a key repeat can start after the guard.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.repeat && event.target.matches('[data-merkliste-remove]')) {
            event.preventDefault();
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            refresh();
            landed = -1;
            if (dirty()) {
                schedule();
            }
        }
    });
    // Side-by-side windows never fire visibilitychange.
    window.addEventListener('focus', refresh);
    // For browsers that skip the hidden state on navigation.
    window.addEventListener('pagehide', () => {
        flush();
        journal.clear();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refresh();
        } else {
            flush();
        }
    });

    render();
    if (dirty()) {
        schedule();
    }
})();
