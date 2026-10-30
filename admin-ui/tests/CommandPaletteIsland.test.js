import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CommandPaletteIsland from '../src/islands/CommandPaletteIsland.vue';

const props = {
    searchUrl: '/admin/api/search',
    loginUrl: '/admin/login',
    title: 'Suchen',
    inputLabel: 'Suchbegriff',
    placeholder: 'Suchbegriff eingeben',
    closeLabel: 'Schliessen',
    hintLabel: 'Mindestens 2 Zeichen.',
    emptyLabel: 'Keine Treffer.',
    resultsOne: '1 Treffer',
    resultsOther: '%n Treffer',
    sessionGoneLabel: 'Sitzung abgelaufen.',
    loginLabel: 'Neu anmelden',
    errorLabel: 'Suche nicht möglich.',
};

const groups = {
    groups: [
        { label: 'Locations', results: [{ type: 'venue', label: 'Schloss', detail: 'Meilen', url: '/admin/locations/1' }, { type: 'venue', label: 'Villa', detail: null, url: '/admin/locations/2' }] },
        { label: 'Firmen', results: [{ type: 'company', label: 'Seeblick AG', detail: null, url: '/admin/firmen/1' }] },
    ],
};

function json(body, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function palette() {
    return mount(CommandPaletteIsland, { props, attachTo: document.body });
}

async function type(island, text) {
    await island.get('input').setValue(text);
    vi.advanceTimersByTime(150);
    await flushPromises();
}

const status = (island) => island.get('[role="status"]').text();

describe('CommandPaletteIsland', () => {
    let island;

    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        island?.unmount();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    it('opens as soon as it is mounted, with focus in the input', async () => {
        island = palette();
        await flushPromises();

        expect(island.get('dialog').element.open).toBe(true);
        expect(document.activeElement).toBe(island.get('input').element);
    });

    it('asks nothing below two characters', async () => {
        vi.stubGlobal('fetch', vi.fn());
        island = palette();

        await type(island, 'a');

        expect(fetch).not.toHaveBeenCalled();
        expect(status(island)).toBe('Mindestens 2 Zeichen.');
    });

    it('debounces', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(groups)));
        island = palette();

        await island.get('input').setValue('sc');
        vi.advanceTimersByTime(100);
        await island.get('input').setValue('sch');
        vi.advanceTimersByTime(100);
        expect(fetch).not.toHaveBeenCalled();

        vi.advanceTimersByTime(50);
        await flushPromises();

        expect(fetch).toHaveBeenCalledOnce();
        expect(fetch.mock.calls[0][0]).toBe('/admin/api/search?q=sch');
    });

    it('aborts the running search on new input', async () => {
        const signals = [];
        vi.stubGlobal('fetch', vi.fn((url, init) => {
            signals.push(init.signal);

            return new Promise(() => {});
        }));
        island = palette();

        await type(island, 'sc');
        await type(island, 'sch');

        expect(signals[0].aborted).toBe(true);
        expect(signals[1].aborted).toBe(false);
    });

    it('shows the groups and the count', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(groups)));
        island = palette();

        await type(island, 'see');

        expect(island.findAll('[role="group"]').map((group) => group.get('.palette__group-label').text())).toEqual(['Locations', 'Firmen']);
        expect(island.findAll('[role="option"]')).toHaveLength(3);
        expect(status(island)).toBe('3 Treffer');
    });

    it('says when there is one result', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json({ groups: [groups.groups[1]] })));
        island = palette();

        await type(island, 'see');

        expect(status(island)).toBe('1 Treffer');
    });

    it('moves through all groups with the keyboard and keeps focus in the input', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(groups)));
        island = palette();

        await type(island, 'see');
        const input = island.get('input');
        expect(input.attributes('aria-activedescendant')).toBe('palette-option-0');

        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });

        expect(input.attributes('aria-activedescendant')).toBe('palette-option-2');
        expect(document.getElementById('palette-option-2').textContent).toContain('Seeblick AG');
        expect(document.getElementById('palette-option-2').getAttribute('aria-selected')).toBe('true');
    });

    it('Enter opens the active result', async () => {
        const assign = vi.fn();
        vi.stubGlobal('location', { assign });
        vi.stubGlobal('fetch', vi.fn(async () => json(groups)));
        island = palette();

        await type(island, 'see');
        await island.get('input').trigger('keydown', { key: 'ArrowDown' });
        await island.get('input').trigger('keydown', { key: 'Enter' });

        expect(assign).toHaveBeenCalledWith('/admin/locations/2');
    });

    it('says when nothing was found', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json({ groups: [] })));
        island = palette();

        await type(island, 'nichts');

        expect(status(island)).toBe('Keine Treffer.');
    });

    it('a lost session shows a link and no redirect', async () => {
        const assign = vi.fn();
        vi.stubGlobal('location', { assign });
        vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 401 })));
        island = palette();

        await type(island, 'see');

        expect(status(island)).toContain('Sitzung abgelaufen.');
        expect(island.get('[role="status"] a').attributes('href')).toBe('/admin/login');
        expect(assign).not.toHaveBeenCalled();
    });

    it('a 500 is an error, not a lost session', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>', { status: 500, headers: { 'Content-Type': 'text/html' } })));
        island = palette();

        await type(island, 'see');

        expect(status(island)).toBe('Suche nicht möglich.');
        expect(island.find('[role="status"] a').exists()).toBe(false);
    });

    it('takes over the trigger and the shortcut once mounted', async () => {
        document.body.innerHTML = '<button data-palette-open></button>';
        island = palette();
        await flushPromises();

        island.get('dialog').element.close();
        await flushPromises();
        expect(island.get('dialog').element.open).toBe(false);

        document.querySelector('button').click();
        await flushPromises();
        expect(island.get('dialog').element.open).toBe(true);

        island.get('dialog').element.close();
        await flushPromises();

        const shortcut = new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, cancelable: true, bubbles: true });
        document.dispatchEvent(shortcut);
        await flushPromises();

        expect(shortcut.defaultPrevented).toBe(true);
        expect(island.get('dialog').element.open).toBe(true);
    });

    it('ignores the shortcut with Shift or Alt', async () => {
        island = palette();
        await flushPromises();
        island.get('dialog').element.close();
        await flushPromises();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, shiftKey: true, bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, altKey: true, bubbles: true }));
        await flushPromises();

        expect(island.get('dialog').element.open).toBe(false);
    });

    it('starts clean after closing', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(groups)));
        island = palette();

        await type(island, 'see');
        island.get('dialog').element.close();
        await flushPromises();

        expect(island.get('input').element.value).toBe('');
        expect(island.findAll('[role="option"]')).toHaveLength(0);
    });

    it('Esc closes, resets the query and drops a debounced search', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(groups)));
        island = palette();

        await type(island, 'see');
        expect(island.findAll('[role="option"]')).toHaveLength(3);
        await island.get('input').setValue('seeb');

        await island.get('input').trigger('keydown', { key: 'Escape' });
        await flushPromises();
        vi.advanceTimersByTime(150);
        await flushPromises();

        expect(island.get('dialog').element.open).toBe(false);
        expect(fetch).toHaveBeenCalledOnce();
        expect(island.get('input').element.value).toBe('');
        expect(island.findAll('[role="option"]')).toHaveLength(0);
        expect(status(island)).toBe('Mindestens 2 Zeichen.');
    });

    it('Esc aborts a search in flight', async () => {
        const signals = [];
        vi.stubGlobal('fetch', vi.fn((url, init) => {
            signals.push(init.signal);

            return new Promise(() => {});
        }));
        island = palette();

        await type(island, 'see');
        await island.get('input').trigger('keydown', { key: 'Escape' });

        expect(signals).toHaveLength(1);
        expect(signals[0].aborted).toBe(true);
    });
});
