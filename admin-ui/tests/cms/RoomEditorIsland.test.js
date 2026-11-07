import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RoomEditorIsland from '../../src/islands/RoomEditorIsland.vue';

const ID = '00000000-0000-7000-8000-000000000000';

function room(id, position, overrides = {}) {
    return {
        id,
        position,
        names: { de: `Saal ${id}` },
        descriptions: { de: 'Hell' },
        capacityMin: 20,
        capacityMax: 80,
        areaM2: 120,
        heightM: '4.20',
        floorMaterial: 'parquet',
        seating: { banquet: { min: 20, max: 80 } },
        fromContent: false,
        photoCount: 2,
        token: `token-${id}`,
        ...overrides,
    };
}

const labels = new Proxy({
    capacityRange: '%min–%max Personen',
    capacityMax: 'bis %max Personen',
    area: '%area m²',
    photoCountOne: '1 Foto',
    photoCountOther: '%count Fotos',
    editTitle: '%name bearbeiten',
    moved: '%label ist jetzt an Position %position.',
    removeText: '%name wird gelöscht.',
    removePhotos: 'Seine Fotos (%count) kommen ans Ende der Galerie.',
}, { get: (target, key) => target[key] ?? key });

function mounted(rooms = [room('a', 1), room('b', 2)]) {
    return mount(RoomEditorIsland, {
        attachTo: document.body,
        props: {
            rooms,
            locales: [{ code: 'de', label: 'Deutsch' }],
            defaultLocale: 'de',
            floorMaterials: [{ value: 'parquet', label: 'Parkett' }],
            seatingTypes: [{ value: 'banquet', label: 'Bankett' }, { value: 'apero', label: 'Apéro' }],
            nameMax: 100,
            descriptionMax: 5000,
            idPlaceholder: ID,
            reloadUrl: '/admin/locations/1/raeume',
            endpoints: { add: '/api/rooms', order: '/api/rooms/order', change: `/api/rooms/${ID}`, remove: `/api/rooms/${ID}` },
            labels,
        },
    });
}

const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

function answering(...responses) {
    const fetchMock = vi.fn(async () => responses.shift());

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

const sent = (fetchMock, call = 0) => JSON.parse(fetchMock.mock.calls[call][1].body);
const names = (island) => island.findAll('.room-list__name').map((name) => name.text());

describe('RoomEditorIsland', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="toast-region"></div>';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    it('lists the rooms with their facts', () => {
        const island = mounted([room('a', 2), room('b', 1, { capacityMin: null, photoCount: 1, fromContent: true, areaM2: null })]);

        expect(names(island)).toEqual(['Saal b', 'Saal a']);
        expect(island.findAll('.room-list__summary').map((summary) => summary.text())).toEqual([
            'bis 80 Personen · 1 Foto',
            '20–80 Personen · 120 m² · 2 Fotos',
        ]);
        expect(island.findAll('.pill').map((pill) => pill.text())).toEqual(['fromContent']);
    });

    it('shows the empty state', () => {
        expect(mounted([]).get('.empty').text()).toContain('emptyTitle');
    });

    it('adds a room with exactly the keys of the form', async () => {
        const island = mounted([]);
        const fetchMock = answering(json({ room: room('a', 1), rooms: [room('a', 1)] }, 201));

        await island.get('[data-room-add]').trigger('click');

        expect(island.get('#room-drawer').attributes('open')).toBeDefined();
        expect(island.get('#room-drawer-title').text()).toBe('addTitle');

        await island.get('#room-name-de').setValue('Seesaal');
        await island.get('#room-capacity-max').setValue(80);
        await island.get('#room-height').setValue(' 4.20 ');
        await island.get('#room-floor').setValue('parquet');
        await island.get('#room-seating-banquet-max').setValue(60);
        await island.get('#room-seating-apero-max').setValue(120);
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/api/rooms');
        expect(fetchMock.mock.calls[0][1].method).toBe('POST');
        expect(sent(fetchMock)).toEqual({
            names: { de: 'Seesaal' },
            descriptions: { de: '' },
            capacityMin: null,
            capacityMax: 120,
            areaM2: null,
            heightM: '4.20',
            floorMaterial: 'parquet',
            seating: { banquet: { min: null, max: 60 }, apero: { min: null, max: 120 } },
        });
        expect(island.get('#room-drawer').attributes('open')).toBeUndefined();
        expect(names(island)).toEqual(['Saal a']);
        expect(document.querySelector('.toast--success').textContent).toContain('added');
    });

    it('edits a room with its token and fills the drawer', async () => {
        const island = mounted();
        const fetchMock = answering(json({ rooms: [room('a', 1, { names: { de: 'Neu' } }), room('b', 2)] }));

        await island.get('[data-room-edit="a"]').trigger('click');

        expect(island.get('#room-drawer-title').text()).toBe('Saal a bearbeiten');
        expect(island.get('#room-name-de').element.value).toBe('Saal a');
        expect(island.get('#room-height').element.value).toBe('4.20');
        expect(island.get('#room-seating-banquet-max').element.value).toBe('80');
        expect(island.get('#room-seating-apero-max').element.value).toBe('');

        await island.get('#room-name-de').setValue('Neu');
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/api/rooms/a');
        expect(fetchMock.mock.calls[0][1].method).toBe('PUT');
        expect(sent(fetchMock)).toMatchObject({ loadedAt: 'token-a', names: { de: 'Neu' }, heightM: '4.20', capacityMin: 20 });
        expect(names(island)).toEqual(['Neu', 'Saal b']);
    });

    it('takes the guest range from the layouts and locks the room fields while one states a maximum', async () => {
        const island = mounted([]);

        await island.get('[data-room-add]').trigger('click');
        await island.get('#room-capacity-min').setValue(5);
        await island.get('#room-capacity-max').setValue(500);

        expect(island.get('#room-capacity-max').attributes('disabled')).toBeUndefined();
        expect(island.find('#room-capacity-max-help').exists()).toBe(false);

        await island.get('#room-seating-banquet-min').setValue(40);
        await island.get('#room-seating-banquet-max').setValue(150);
        await island.get('#room-seating-apero-min').setValue(30);
        await island.get('#room-seating-apero-max').setValue(220);

        expect(island.get('#room-capacity-min').element.value).toBe('30');
        expect(island.get('#room-capacity-max').element.value).toBe('220');
        expect(island.get('#room-capacity-max').attributes('disabled')).toBeDefined();
        expect(island.get('#room-capacity-max-help').text()).toBe('capacityFromLayouts');

        await island.get('#room-seating-banquet-max').setValue('');
        await island.get('#room-seating-apero-max').setValue('');

        expect(island.get('#room-capacity-max').attributes('disabled')).toBeUndefined();
        expect(island.get('#room-capacity-max').element.value).toBe('220');
    });

    it('sends the token of a reorder that landed after the drawer opened', async () => {
        const island = mounted();
        let release;
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => {
                release = resolve;
            }))
            .mockImplementationOnce(async () => json({ rooms: [room('b', 1), room('a', 2)] }));

        vi.stubGlobal('fetch', fetchMock);

        await island.get('[data-move="down-a"]').trigger('click');
        await island.get('[data-room-edit="a"]').trigger('click');
        await flushPromises();

        release(json({ rooms: [room('b', 1), room('a', 2, { token: 'moved' })] }));
        await flushPromises();

        await island.get('#room-form').trigger('submit');
        await flushPromises();

        expect(sent(fetchMock, 1).loadedAt).toBe('moved');
    });

    it('reads the token when the request leaves, not at the submit', async () => {
        const island = mounted();
        let release;
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => {
                release = resolve;
            }))
            .mockImplementationOnce(async () => json({ rooms: [room('b', 1), room('a', 2)] }));

        vi.stubGlobal('fetch', fetchMock);

        await island.get('[data-move="down-a"]').trigger('click');
        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledOnce();

        release(json({ rooms: [room('b', 1), room('a', 2, { token: 'moved' })] }));
        await flushPromises();

        expect(sent(fetchMock, 1).loadedAt).toBe('moved');
    });

    function heldSave() {
        let release;
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => {
                release = resolve;
            }))
            .mockImplementation(async () => json({ rooms: [room('b', 1), room('a', 2)] }));

        vi.stubGlobal('fetch', fetchMock);

        return { fetchMock, release: (answer) => release(answer) };
    }

    const busy = (island) => island.get('#room-drawer').attributes('aria-busy') === 'true';
    const isInert = (island, selector) => island.get(selector).attributes('inert') !== undefined;

    it('keeps the drawer open and inert from Speichern until the answer', async () => {
        const island = mounted();
        const { release } = heldSave();

        await island.get('[data-room-edit="a"]').trigger('click');

        expect(busy(island)).toBe(false);

        await island.get('#room-form').trigger('submit');

        expect(busy(island)).toBe(true);
        expect(isInert(island, '#room-drawer .modal__body')).toBe(true);
        expect(isInert(island, '#room-drawer .modal__footer')).toBe(true);
        expect(isInert(island, '#room-drawer .modal__header form')).toBe(true);

        const dialog = island.get('#room-drawer').element;
        const escape = new Event('cancel', { cancelable: true });

        dialog.dispatchEvent(escape);
        expect(escape.defaultPrevented).toBe(true);

        // A second Esc skips a prevented cancel, and js/dialog.js's backdrop click closes the dialog outright.
        dialog.close();
        await flushPromises();

        expect(dialog.open).toBe(true);
        expect(island.get('#room-drawer-title').text()).toBe('%name bearbeiten'.replace('%name', 'Saal a'));

        release(json({ rooms: [room('a', 1), room('b', 2)] }));
        await flushPromises();

        expect(dialog.open).toBe(false);
        expect(document.querySelector('.toast')?.textContent).toContain('saved');
    });

    it.each([
        ['a 422', () => json({ errors: { 'names.de': ['Fehlt.'] } }, 422)],
        ['a 409', () => json({ errors: { '': ['Stale'] } }, 409)],
        ['a 500', () => new Response('x', { status: 500 })],
        ['a lost session', () => new Response('', { status: 401 })],
    ])('gives the form back on %s, on the same room', async (_, answer) => {
        const island = mounted();
        const { release } = heldSave();

        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-name-de').setValue('Seesaal');
        await island.get('#room-form').trigger('submit');
        release(answer());
        await flushPromises();

        expect(busy(island)).toBe(false);
        expect(isInert(island, '#room-drawer .modal__body')).toBe(false);
        expect(isInert(island, '#room-drawer .modal__footer')).toBe(false);
        expect(island.get('#room-drawer').element.open).toBe(true);
        expect(island.get('#room-name-de').element.value).toBe('Seesaal');
    });

    it('gives the form back when the request hangs, and aborts it', async () => {
        vi.useFakeTimers();

        try {
            const island = mounted();
            const fetchMock = vi.fn((_, init) => new Promise((_resolve, reject) => {
                init.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            }));

            vi.stubGlobal('fetch', fetchMock);

            await island.get('[data-room-edit="a"]').trigger('click');
            await island.get('#room-form').trigger('submit');
            await vi.advanceTimersByTimeAsync(89999);

            expect(busy(island)).toBe(true);

            await vi.advanceTimersByTimeAsync(1);
            await flushPromises();

            expect(busy(island)).toBe(false);
            expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
            expect(island.get('#room-drawer [role="alert"]').text()).toContain('failed');
        } finally {
            vi.useRealTimers();
        }
    });

    it('sends the name and description of the press when the save is held', async () => {
        const island = mounted();
        const { fetchMock, release } = heldSave();

        await island.get('[data-move="down-a"]').trigger('click');
        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-name-de').setValue('Seesaal');
        await island.get('#room-description-de').setValue('Am See');
        await island.get('#room-form').trigger('submit');
        await island.get('#room-name-de').setValue('Später');
        await island.get('#room-description-de').setValue('Später');

        release(json({ rooms: [room('b', 1), room('a', 2, { token: 'moved' })] }));
        await flushPromises();

        expect(fetchMock.mock.calls[1][0]).toBe('/api/rooms/a');
        expect(sent(fetchMock, 1)).toMatchObject({ loadedAt: 'moved', names: { de: 'Seesaal' }, descriptions: { de: 'Am See' } });
    });

    it('offers a reload in the drawer when a save finds the session gone', async () => {
        const island = mounted();
        answering(new Response('', { status: 401 }));

        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        const alert = island.get('#room-drawer [role="alert"]');
        expect(alert.text()).toContain('sessionGone');
        expect(alert.get('a').attributes('href')).toBe('/admin/locations/1/raeume');
    });

    it('offers a reload when a reorder meets a dead token, and clears it on the next write', async () => {
        const island = mounted();
        answering(json({ error: 'forbidden' }, 403), json({ rooms: [room('b', 1), room('a', 2)] }));

        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();

        expect(island.get('.media-alert').text()).toContain('sessionGone');
        expect(island.get('.media-alert a').attributes('href')).toBe('/admin/locations/1/raeume');

        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();

        expect(island.find('.media-alert').exists()).toBe(false);
    });

    it('shows a 422 by its field, dotted paths included, and focuses the first', async () => {
        const island = mounted();
        answering(json({ errors: { 'names.de': ['Fehlt.'], 'seating.banquet.max': ['Zu klein.'] } }, 422));

        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        expect(island.get('#room-name-de-error').text()).toBe('Fehlt.');
        expect(island.get('#room-name-de').attributes('aria-invalid')).toBe('true');
        expect(island.get('#room-seating-banquet-max-error').text()).toBe('Zu klein.');
        expect(document.activeElement.id).toBe('room-name-de');
        expect(island.get('#room-drawer').attributes('open')).toBeDefined();
    });

    it('shows an error of an unknown field instead of dropping it', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Unbekannt.'] } }, 422));

        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        expect(island.get('.form-error').text()).toBe('Unbekannt.');
    });

    it('keeps the input and offers a reload on a 409', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Stale'] } }, 409));

        await island.get('[data-room-edit="a"]').trigger('click');
        await island.get('#room-name-de').setValue('Neu');
        await island.get('#room-form').trigger('submit');
        await flushPromises();

        const alert = island.get('#room-drawer [role="alert"]');
        expect(alert.text()).toContain('stale');
        expect(alert.get('a').attributes('href')).toBe('/admin/locations/1/raeume');
        expect(island.get('#room-name-de').element.value).toBe('Neu');
    });

    it('reorders by keyboard with one PUT', async () => {
        const island = mounted();
        const fetchMock = answering(json({ rooms: [room('b', 1), room('a', 2)] }));

        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(fetchMock.mock.calls[0][0]).toBe('/api/rooms/order');
        expect(sent(fetchMock)).toEqual({ ids: ['b', 'a'] });
        expect(names(island)).toEqual(['Saal b', 'Saal a']);
    });

    it('puts the old order back when a reorder fails', async () => {
        const island = mounted();
        answering(new Response('', { status: 500 }));

        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();

        expect(names(island)).toEqual(['Saal a', 'Saal b']);
        expect(document.querySelector('.toast--error').textContent).toContain('orderFailed');
    });

    it('confirms a removal, states that the photos move, and returns focus', async () => {
        const island = mounted();
        const fetchMock = answering(json({ rooms: [room('b', 1)] }));

        await island.get('[data-room-remove="a"]').trigger('click');

        const modal = island.get('#room-remove');
        expect(modal.attributes('open')).toBeDefined();
        expect(modal.text()).toContain('Saal a wird gelöscht.');
        expect(modal.text()).toContain('Seine Fotos (2) kommen ans Ende der Galerie.');

        await island.get('#room-remove [data-confirm-remove]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/api/rooms/a');
        expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
        expect(names(island)).toEqual(['Saal b']);
        expect(document.activeElement.hasAttribute('data-room-add')).toBe(true);
    });

    it('leaves the photo sentence out for a room without photos', async () => {
        const island = mounted([room('a', 1, { photoCount: 0 })]);

        await island.get('[data-room-remove="a"]').trigger('click');

        expect(island.get('#room-remove').text()).not.toContain('Seine Fotos');
    });

    it('offers a reload when the room to remove is gone', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Weg'] } }, 404));

        await island.get('[data-room-remove="a"]').trigger('click');
        await island.get('#room-remove [data-confirm-remove]').trigger('click');
        await flushPromises();

        expect(island.get('.media-alert').text()).toContain('notFound');
    });
});
