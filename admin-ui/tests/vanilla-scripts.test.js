import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

let shortcutOnLoad;

// The delegated scripts of assets/js/admin/, which listen on document: imported once, as a page does.
beforeAll(async () => {
    Object.defineProperty(navigator, 'platform', { value: 'Win32', configurable: true });
    document.body.innerHTML = '<kbd data-shortcut-other="Strg+K">⌘K</kbd>';

    await import('../../assets/js/admin/palette-trigger.js');
    shortcutOnLoad = document.querySelector('kbd').textContent;

    await import('../../assets/js/admin/char-counter.js');
    await import('../../assets/js/admin/markdown-preview.js');
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
});

describe('palette-trigger.js', () => {
    it('shows the shortcut of the platform', () => {
        expect(shortcutOnLoad).toBe('Strg+K');
    });

    it('does nothing once no deferred island is left', () => {
        const event = new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, cancelable: true, bubbles: true });

        document.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('removes the attribute on the shortcut, and restores it after a failed import', async () => {
        document.body.innerHTML = '<div data-island="command-palette" data-island-defer="/nonexistent-entry.js"></div>';
        const mount = document.querySelector('[data-island]');
        const event = new KeyboardEvent('keydown', { key: 'K', metaKey: true, cancelable: true, bubbles: true });

        document.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(mount.dataset.islandDefer).toBeUndefined();
        await vi.waitFor(() => expect(mount.dataset.islandDefer).toBe('/nonexistent-entry.js'));
    });

    it('opens on a click on the trigger', () => {
        document.body.innerHTML = '<button data-palette-open><span>Suchen</span></button><div data-island="x" data-island-defer="/nonexistent-entry.js"></div>';

        document.querySelector('span').click();

        expect(document.querySelector('[data-island]').dataset.islandDefer).toBeUndefined();
    });

    it('ignores the shortcut with Shift', () => {
        document.body.innerHTML = '<div data-island="x" data-island-defer="/e.js"></div>';

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, shiftKey: true, bubbles: true }));

        expect(document.querySelector('[data-island]').dataset.islandDefer).toBe('/e.js');
    });
});

describe('char-counter.js', () => {
    function field(value, max = 10) {
        document.body.innerHTML = `<textarea id="f" data-counter-max="${max}"></textarea><span data-counter-for="f"></span>`;
        const textarea = document.querySelector('textarea');
        textarea.value = value;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        return document.querySelector('[data-counter-for]');
    }

    it('counts a newline as the \\r\\n the browser submits', () => {
        expect(field('a\nb').textContent).toBe('4 / 10');
    });

    it('counts an emoji as one character, as mb_strlen does', () => {
        expect(field('😀a').textContent).toBe('2 / 10');
    });

    it('ignores what Symfony trims', () => {
        expect(field('  ab \n').textContent).toBe('2 / 10');
    });

    it('marks the limit passed', () => {
        expect(field('abc', 2).classList.contains('field__counter--over')).toBe(true);
        expect(field('ab', 2).classList.contains('field__counter--over')).toBe(false);
    });
});

describe('markdown-preview.js', () => {
    function page() {
        document.head.innerHTML = '<meta name="csrf-token" content="token-1">';
        document.body.innerHTML = `
            <div data-markdown data-markdown-url="/preview" data-session-gone="Sitzung weg" data-error="Fehler">
                <button data-markdown-preview>Vorschau</button>
                <textarea>**fett**</textarea>
                <p data-markdown-status hidden></p>
                <div data-markdown-output></div>
            </div>`;

        return {
            status: document.querySelector('[data-markdown-status]'),
            output: document.querySelector('[data-markdown-output]'),
        };
    }

    const preview = async (response) => {
        vi.stubGlobal('fetch', vi.fn(async () => response));
        const elements = page();
        document.querySelector('[data-markdown-preview]').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await new Promise((resolve) => {
            setTimeout(resolve);
        });

        return elements;
    };

    it('posts the text with the token and shows the answer', async () => {
        const { output, status } = await preview(new Response('{"html":"<p><strong>fett</strong></p>"}', { headers: { 'Content-Type': 'application/json' } }));

        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/preview');
        expect(init.method).toBe('POST');
        expect(init.redirect).toBe('manual');
        expect(init.headers['X-CSRF-Token']).toBe('token-1');
        expect(init.body).toBe('{"text":"**fett**"}');
        expect(output.innerHTML).toBe('<p><strong>fett</strong></p>');
        expect(status.hidden).toBe(true);
    });

    it.each([413, 500, 504])('a %i is an error and not a lost session', async (code) => {
        const { status } = await preview(new Response('<html>', { status: code, headers: { 'Content-Type': 'text/html' } }));

        expect(status.textContent).toBe('Fehler');
    });

    it('an opaque redirect is a lost session', async () => {
        const redirect = Object.defineProperties(new Response(null), { type: { value: 'opaqueredirect' }, ok: { value: false }, status: { value: 0 } });

        const { status } = await preview(redirect);

        expect(status.textContent).toBe('Sitzung weg');
        expect(status.hidden).toBe(false);
    });

    it('a 401 is a lost session', async () => {
        const { status } = await preview(new Response(null, { status: 401 }));

        expect(status.textContent).toBe('Sitzung weg');
    });

    it('a login page delivered as a 200 is a lost session', async () => {
        const { status } = await preview(new Response('<html>', { headers: { 'Content-Type': 'text/html' } }));

        expect(status.textContent).toBe('Sitzung weg');
    });

    it('a network failure is an error', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        }));
        const { status } = page();

        document.querySelector('[data-markdown-preview]').click();
        await vi.waitFor(() => expect(status.textContent).toBe('Fehler'));
    });
});
