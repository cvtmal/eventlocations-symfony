import { enableAutoUnmount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RequestFailed, SessionGone, ValidationFailed } from '../../src/api.js';
import { uploadFile } from '../../src/cms/upload.js';
import { answering, dropFiles, json, mounted, photo, sent, tile } from './photoManagerFixture.js';

vi.mock('../../src/cms/upload.js', () => ({ uploadFile: vi.fn() }));

enableAutoUnmount(afterEach); // A mounted island keeps its beforeunload listener.

const covers = (island) => island.findAll('.photo-tile').map((candidate) => candidate.text().includes('cover'));

describe('PhotoManagerIsland', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="toast-region"></div>';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        uploadFile.mockReset();
        document.body.replaceChildren();
    });

    it('shows the gallery, the rooms and the cover on position 1', () => {
        const island = mounted([photo('a', 1), photo('b', 2), photo('r', 1, { roomId: 'room-1', alts: {} })]);

        expect(island.findAll('.photo-scope__title').map((title) => title.text())).toEqual(['gallery · 2 Fotos', 'Seesaal · 1 Foto']);
        expect(covers(island)).toEqual([true, false, false]);
        expect(tile(island, 2).text()).toContain('altMissing');
        expect(tile(island, 0).text()).not.toContain('altMissing');
    });

    it('shows the empty states', () => {
        const island = mounted([]);

        expect(island.get('.empty').text()).toContain('emptyTitle');
        expect(island.get('.photo-scope__empty').text()).toBe('roomEmpty');
    });

    it('reorders by keyboard with one PUT and keeps the cover on position 1', async () => {
        const island = mounted();
        const fetchMock = answering(json({ photos: [photo('b', 1), photo('a', 2), photo('c', 3)] }));

        await island.get('[data-move="up-b"]').trigger('click');

        expect(covers(island)).toEqual([true, false, false]);
        expect(tile(island, 0).find('img').attributes('src')).toContain('/b-640');

        await flushPromises();

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(fetchMock.mock.calls[0][0]).toBe('/api/photos/order');
        expect(fetchMock.mock.calls[0][1].method).toBe('PUT');
        expect(sent(fetchMock)).toEqual({ roomId: null, ids: ['b', 'a', 'c'] });
        expect(covers(island)).toEqual([true, false, false]);
        expect(tile(island, 0).find('img').attributes('src')).toContain('/b-640');
    });

    it('sends the room as the scope of a reorder', async () => {
        const island = mounted([photo('a', 1, { roomId: 'room-1' }), photo('b', 2, { roomId: 'room-1' })]);
        const fetchMock = answering(json({ photos: [photo('b', 1, { roomId: 'room-1' }), photo('a', 2, { roomId: 'room-1' })] }));

        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();

        expect(sent(fetchMock).roomId).toBe('room-1');
    });

    it('puts the old order back and toasts when a reorder fails', async () => {
        const island = mounted();
        answering(new Response('', { status: 500 }));

        await island.get('[data-move="up-b"]').trigger('click');
        await flushPromises();

        expect(tile(island, 0).find('img').attributes('src')).toContain('/a-640');
        expect(document.querySelector('.toast--error').textContent).toContain('orderFailed');
    });

    it('offers a reload when a reorder is stale', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Stale'] } }, 409));

        await island.get('[data-move="up-b"]').trigger('click');
        await flushPromises();

        expect(island.get('[role="alert"]').text()).toContain('stale');
        expect(island.get('[role="alert"] a').attributes('href')).toBe('/admin/locations/1/fotos');
    });

    it('offers a reload when a reorder finds the session gone', async () => {
        const island = mounted();
        answering(new Response('', { status: 401 }));

        await island.get('[data-move="up-b"]').trigger('click');
        await flushPromises();

        expect(island.get('[role="alert"]').text()).toContain('sessionGone');
        expect(island.get('[role="alert"] a').attributes('href')).toBe('/admin/locations/1/fotos');
        expect(tile(island, 0).find('img').attributes('src')).toContain('/a-640');
    });

    it('offers a reload when a save meets a dead token', async () => {
        const island = mounted();
        answering(json({ error: 'forbidden' }, 403));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(island.get('[role="alert"]').text()).toContain('sessionGone');
        expect(island.get('[role="alert"] a').attributes('href')).toBe('/admin/locations/1/fotos');
        expect(island.get('#photo-alt-de').element.value).toBe('Neu');
    });

    it('clears the alert on the next write that goes through', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Stale'] } }, 409), json({ photos: [photo('b', 1), photo('a', 2), photo('c', 3)] }));

        await island.get('[data-move="up-b"]').trigger('click');
        await flushPromises();

        expect(island.find('[role="alert"]').exists()).toBe(true);

        await island.get('[data-move="up-b"]').trigger('click');
        await flushPromises();

        expect(island.find('[role="alert"]').exists()).toBe(false);
    });

    it('opens the details of a photo with its size and fallback', async () => {
        const island = mounted();

        expect(island.get('.photo-panel').text()).toBe('detailsNone');

        await tile(island, 1).trigger('click');

        expect(tile(island, 1).attributes('aria-pressed')).toBe('true');
        expect(island.get('.photo-panel__title').text()).toBe('Foto 2');
        expect(island.get('.photo-panel__facts').text()).toBe('2400 × 1600 px · WEBP, JPG · 360–1920 px');
        expect(island.get('#photo-alt-de').element.value).toBe('Alt b');
        expect(island.get('.photo-panel').text()).toContain('Ohne Text: Villa, Bild 2.');
        expect(island.get('.field__counter').text()).toBe('5 / 700');
    });

    it('saves only what changed', async () => {
        const island = mounted();
        const fetchMock = answering(json({ photos: [photo('a', 1, { alts: { de: 'Neu' }, token: 'token-2' }), photo('b', 2), photo('c', 3)] }));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(fetchMock.mock.calls[0][0]).toBe('/api/photos/a/alt');
        expect(sent(fetchMock)).toEqual({ loadedAt: 'token-a', alts: { de: 'Neu' } });
        expect(document.querySelector('.toast--success').textContent).toContain('saved');
    });

    it('saves the alt text, then the room, and sends nothing without a change', async () => {
        const island = mounted();
        const moved = [photo('b', 1), photo('c', 2), photo('a', 1, { roomId: 'room-1', alts: { de: 'Neu' } })];
        const fetchMock = answering(json({ photos: moved }), json({ photos: moved }));

        await tile(island, 0).trigger('click');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();

        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('#photo-room').setValue('room-1');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(['/api/photos/a/alt', '/api/photos/a/room']);
        expect(sent(fetchMock, 1)).toEqual({ roomId: 'room-1' });
        expect(island.findAll('.photo-scope__title')[1].text()).toBe('Seesaal · 1 Foto');
    });

    it('sends the token a reorder left behind', async () => {
        const island = mounted();
        const fetchMock = answering(
            json({ photos: [photo('b', 1), photo('a', 2, { token: 'moved' }), photo('c', 3)] }),
            json({ photos: [photo('b', 1), photo('a', 2, { alts: { de: 'Neu' }, token: 'saved' }), photo('c', 3)] }),
        );

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();
        await island.get('.photo-panel .btn--primary').trigger('click');
        await flushPromises();

        expect(sent(fetchMock, 1)).toEqual({ loadedAt: 'moved', alts: { de: 'Neu' } });
    });

    it('reads the token when the request leaves, not when Speichern was pressed', async () => {
        const island = mounted();
        let release;
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => {
                release = resolve;
            }))
            .mockImplementationOnce(async () => json({ photos: [photo('b', 1), photo('a', 2, { alts: { de: 'Neu' } }), photo('c', 3)] }));

        vi.stubGlobal('fetch', fetchMock);

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('[data-move="down-a"]').trigger('click');
        await flushPromises();
        await island.get('.photo-panel .btn--primary').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledOnce();

        release(json({ photos: [photo('b', 1), photo('a', 2, { token: 'moved' }), photo('c', 3)] }));
        await flushPromises();

        expect(sent(fetchMock, 1).loadedAt).toBe('moved');
        expect(island.find('[role="alert"]').exists()).toBe(false);
    });

    it('sends the text as the counter counts it', async () => {
        const island = mounted();
        const fetchMock = answering(json({ photos: [photo('a', 1), photo('b', 2), photo('c', 3)] }));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue(`${'x'.repeat(700)}  `);

        expect(island.get('.field__counter').text()).toBe('700 / 700');

        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(sent(fetchMock).alts.de).toHaveLength(700);
    });

    it('keeps the room change when the alt text saved and the room failed', async () => {
        const island = mounted();
        const savedAlt = [photo('a', 1, { alts: { de: 'Neu' }, token: 'saved' }), photo('b', 2), photo('c', 3)];
        const moved = [photo('b', 1), photo('c', 2), photo('a', 1, { roomId: 'room-1', alts: { de: 'Neu' }, token: 'moved' })];
        const fetchMock = answering(json({ photos: savedAlt }), new Response('', { status: 500 }), json({ photos: moved }));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('#photo-room').setValue('room-1');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(island.get('.media-alert').text()).toContain('failed');
        expect(document.querySelector('.toast--success')).toBeNull();
        expect(island.get('#photo-room').element.value).toBe('room-1');
        expect(island.get('#photo-alt-de').element.value).toBe('Neu');

        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(['/api/photos/a/alt', '/api/photos/a/room', '/api/photos/a/room']);
        expect(sent(fetchMock, 2)).toEqual({ roomId: 'room-1' });
        expect(island.find('.media-alert').exists()).toBe(false);
    });

    it('shows a 422 on an unknown field instead of dropping it', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Unbekannt.'] } }, 422));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(island.get('.media-alert').text()).toBe('Unbekannt.');
        expect(island.find('.media-alert a').exists()).toBe(false);
    });

    it('moves focus to the panel when a photo is chosen', async () => {
        const island = mounted();

        await tile(island, 1).trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(island.get('.photo-panel__title').element);
    });

    it('scrolls to the panel once it has rendered', async () => {
        const island = mounted();
        let rendered = null;

        island.get('.photo-panel').element.scrollIntoView = () => {
            rendered = island.find('.photo-panel__actions').exists();
        };

        await tile(island, 0).trigger('click');
        await flushPromises();

        expect(rendered).toBe(true);
    });

    it('shows a 422 by its field', async () => {
        const island = mounted();
        answering(json({ errors: { 'alts.de': ['Zu lang.'] } }, 422));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('x'.repeat(701));
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        const field = island.get('#photo-alt-de');
        expect(island.get('#photo-alt-de-error').text()).toBe('Zu lang.');
        expect(field.attributes('aria-invalid')).toBe('true');
        expect(field.attributes('aria-describedby')).toContain('photo-alt-de-error');
        expect(island.get('.field__counter--over').text()).toBe('701 / 700');
    });

    it('shows a stale save with a reload link and keeps the input', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Stale'] } }, 409));

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('.btn--primary').trigger('click');
        await flushPromises();

        expect(island.get('[role="alert"]').text()).toContain('stale');
        expect(island.get('#photo-alt-de').element.value).toBe('Neu');
    });

    it('keeps typed text while an upload answers and when another tile is picked', async () => {
        const island = mounted();
        uploadFile.mockResolvedValue({ duplicate: false, photos: [photo('a', 1), photo('b', 2), photo('c', 3), photo('d', 4)] });

        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Halb fertig');
        await dropFiles(island, new File(['x'], 'd.jpg'));
        await flushPromises();

        expect(island.findAll('.photo-tile')).toHaveLength(4);
        expect(island.get('#photo-alt-de').element.value).toBe('Halb fertig');

        await tile(island, 1).trigger('click');
        await tile(island, 0).trigger('click');

        expect(island.get('#photo-alt-de').element.value).toBe('Halb fertig');
    });

    it('confirms a removal, and warns for the last gallery photo', async () => {
        const island = mounted([photo('a', 1)]);
        const fetchMock = answering(json({ photos: [] }));

        await tile(island, 0).trigger('click');
        await island.get('.photo-panel .btn--danger').trigger('click');

        expect(island.get('#photo-remove').attributes('open')).toBeDefined();
        expect(island.get('#photo-remove').text()).toContain('removeLast');

        await island.get('[data-confirm-remove]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/api/photos/a');
        expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
        expect(island.find('.photo-tile').exists()).toBe(false);
        expect(document.activeElement.classList.contains('photo-panel__none')).toBe(true);
    });

    it('keeps the photo and says so when a removal fails', async () => {
        const island = mounted();
        answering(new Response('', { status: 500 }));

        await tile(island, 0).trigger('click');
        await island.get('.photo-panel .btn--danger').trigger('click');
        await island.get('[data-confirm-remove]').trigger('click');
        await flushPromises();

        expect(island.findAll('.photo-tile')).toHaveLength(3);
        expect(island.get('.media-alert').text()).toContain('failed');
        expect(island.get('.photo-panel__title').text()).toBe('Foto 1');
        expect(document.querySelector('.toast--success')).toBeNull();
    });

    it('offers a reload when the photo to remove is gone', async () => {
        const island = mounted();
        answering(json({ errors: { '': ['Weg'] } }, 404));

        await tile(island, 0).trigger('click');
        await island.get('.photo-panel .btn--danger').trigger('click');
        await island.get('[data-confirm-remove]').trigger('click');
        await flushPromises();

        expect(island.get('.media-alert').text()).toContain('notFound');
        expect(island.get('.media-alert a').attributes('href')).toBe('/admin/locations/1/fotos');
    });

    describe('the cover in the page header', () => {
        const header = () => document.querySelector('.record-header__media');

        beforeEach(() => {
            document.body.insertAdjacentHTML('beforeend', '<div class="record-header__media"><picture><source srcset="/old.webp"><img src="/old.jpg" alt=""></picture></div>');
        });

        it('follows a new first photo, past the old <picture>', async () => {
            const island = mounted();
            answering(json({ photos: [photo('b', 1), photo('a', 2), photo('c', 3)] }));

            await island.get('[data-move="up-b"]').trigger('click');
            await flushPromises();

            expect(header().querySelector('picture')).toBeNull();
            expect(header().querySelector('img').getAttribute('src')).toBe('/media/1/b-640.jpg');
        });

        it('stays as rendered while the first photo stays', async () => {
            const island = mounted();
            answering(json({ photos: [photo('a', 1), photo('c', 2), photo('b', 3)] }));

            await island.get('[data-move="up-c"]').trigger('click');
            await flushPromises();

            expect(header().querySelector('picture')).not.toBeNull();
        });

        it('falls back to the placeholder without a gallery photo, and comes back with an upload', async () => {
            const island = mounted([photo('a', 1)]);
            answering(json({ photos: [] }));
            uploadFile.mockResolvedValue({ duplicate: false, photos: [photo('n', 1)] });

            await tile(island, 0).trigger('click');
            await island.get('.photo-panel .btn--danger').trigger('click');
            await island.get('[data-confirm-remove]').trigger('click');
            await flushPromises();

            expect(header().querySelector('img')).toBeNull();
            expect(header().querySelector('.record-header__placeholder svg')).not.toBeNull();

            await dropFiles(island, new File(['x'], 'n.jpg'));
            await flushPromises();

            expect(header().querySelector('img').getAttribute('src')).toBe('/media/1/n-640.jpg');
            expect(header().querySelector('.record-header__placeholder')).toBeNull();
        });
    });

    it('uses the plain text for a photo that is not the last one', async () => {
        const island = mounted();

        await tile(island, 0).trigger('click');
        await island.get('.photo-panel .btn--danger').trigger('click');

        expect(island.get('#photo-remove').text()).toContain('removeText');
    });

    it('shows the queue with a determinate, then an indeterminate bar, and adds the photo', async () => {
        const island = mounted();
        let finish;

        uploadFile.mockImplementation((url, file, { onProgress }) => new Promise((resolve) => {
            onProgress({ phase: 'sending', loaded: 4, total: 10 });
            finish = () => {
                onProgress({ phase: 'processing' });

                return resolve;
            };
        }));

        await dropFiles(island, new File(['x'], 'seesaal.jpg'));

        expect(uploadFile.mock.calls[0][0]).toBe('/api/photos');
        expect(island.get('.photo-queue__item').text()).toContain('seesaal.jpg');
        expect(island.get('.photo-queue__state').text()).toBe('queueSending');
        expect(island.get('.photo-queue__state').attributes('aria-live')).toBe('polite');
        expect(island.get('progress').attributes('value')).toBe('4');
        expect(island.get('progress').attributes('max')).toBe('10');

        const resolve = finish();
        await island.vm.$nextTick();

        expect(island.get('.photo-queue__state').text()).toBe('queueProcessing');
        expect(island.get('progress').element.hasAttribute('value')).toBe(false);

        resolve({ duplicate: false, photos: [photo('a', 1), photo('b', 2), photo('c', 3), photo('d', 4)] });
        await flushPromises();

        expect(island.get('.photo-queue__state').text()).toBe('queueDone');
        expect(island.findAll('.photo-tile')).toHaveLength(4);
    });

    it('says when a file was already there', async () => {
        const island = mounted();
        uploadFile.mockResolvedValue({ duplicate: true, photos: [photo('a', 1), photo('b', 2), photo('c', 3)] });

        await dropFiles(island, new File(['x'], 'a.jpg'));
        await flushPromises();

        expect(island.get('.photo-queue__state').text()).toBe('queueDuplicate');
    });

    it('refuses a too large file before sending and after a 413', async () => {
        const island = mounted();
        const huge = new File(['x'], 'huge.jpg');

        Object.defineProperty(huge, 'size', { value: 26_000_000 });
        uploadFile.mockRejectedValue(new RequestFailed(413));

        await dropFiles(island, huge);

        expect(uploadFile).not.toHaveBeenCalled();
        expect(island.get('.photo-queue__state').text()).toBe('queueTooLarge');

        await dropFiles(island, new File(['x'], 'nginx.jpg'));
        await flushPromises();

        const states = island.findAll('.photo-queue__state');
        expect(states[1].text()).toBe('queueTooLarge');
        expect(states[1].classes()).toContain('photo-queue__state--error');
        expect(island.find('[role="alert"]').exists()).toBe(false);
    });

    it('offers a retry for a failed upload', async () => {
        const island = mounted();
        uploadFile.mockRejectedValueOnce(new RequestFailed(500));
        uploadFile.mockResolvedValueOnce({ duplicate: false, photos: [photo('a', 1)] });

        await dropFiles(island, new File(['x'], 'a.jpg'));
        await flushPromises();

        expect(island.get('.photo-queue__state').text()).toBe('queueFailed');

        await island.get('.photo-queue__item .btn').trigger('click');
        await flushPromises();

        expect(uploadFile).toHaveBeenCalledTimes(2);
        expect(island.get('.photo-queue__state').text()).toBe('queueDone');
    });

    it('stops the queue at a lost session, names the waiting files and points to a reload', async () => {
        const island = mounted();
        uploadFile.mockRejectedValueOnce(new SessionGone());

        await dropFiles(island, new File(['x'], 'a.jpg'), new File(['x'], 'b.jpg'));
        await flushPromises();

        const banner = island.get('.photo-queue__halted');
        expect(banner.text()).toContain('Neu laden, dann nochmals: a.jpg, b.jpg');
        expect(banner.get('a').attributes('href')).toBe('/admin/locations/1/fotos');
        expect(banner.find('button').exists()).toBe(false);
        expect(island.findAll('.photo-queue__state').map((state) => state.text())).toEqual(['queueWaiting', 'queueWaiting']);
        expect(uploadFile).toHaveBeenCalledOnce();
    });

    it('stops the queue the same way at a dead token', async () => {
        const island = mounted();
        uploadFile.mockRejectedValueOnce(new RequestFailed(403));

        await dropFiles(island, new File(['x'], 'a.jpg'));
        await flushPromises();

        expect(island.get('.photo-queue__halted').text()).toContain('a.jpg');
        expect(island.find('.photo-queue__item .btn').exists()).toBe(false);
    });

    it('shows the server message of a refused file, as for the PNG cap', async () => {
        const island = mounted();
        uploadFile.mockRejectedValueOnce(new ValidationFailed({ file: ['Ein PNG darf höchstens 24 MP haben.'] }));

        await dropFiles(island, new File(['x'], 'plan.png'));
        await flushPromises();

        const state = island.get('.photo-queue__state');
        expect(state.text()).toBe('Ein PNG darf höchstens 24 MP haben.');
        expect(state.classes()).toContain('photo-queue__state--error');
    });

    it('keeps focus in the queue when a retry button goes away', async () => {
        const island = mounted();
        uploadFile.mockRejectedValueOnce(new RequestFailed(500));
        uploadFile.mockReturnValueOnce(new Promise(() => {}));

        await dropFiles(island, new File(['x'], 'a.jpg'));
        await flushPromises();
        await island.get('.photo-queue__item .btn').trigger('click');
        await flushPromises();

        expect(island.find('.photo-queue__item .btn').exists()).toBe(false);
        expect(document.activeElement).toBe(island.get('.photo-queue').element);
    });

    it('moves focus to the drop zone when the last queue row is dismissed', async () => {
        const island = mounted();
        const huge = new File(['x'], 'huge.jpg');

        Object.defineProperty(huge, 'size', { value: 26_000_000 });

        await dropFiles(island, huge);
        await island.get('.photo-queue__item .btn').trigger('click');
        await flushPromises();

        expect(island.find('.photo-queue').exists()).toBe(false);
        expect(document.activeElement.id).toBe('photo-upload');
    });

    const wouldWarn = () => {
        const event = new Event('beforeunload', { cancelable: true });

        window.dispatchEvent(event);

        return event.defaultPrevented;
    };

    it('warns before leaving during an upload', async () => {
        const island = mounted();

        expect(wouldWarn()).toBe(false);

        uploadFile.mockReturnValue(new Promise(() => {}));
        await dropFiles(island, new File(['x'], 'a.jpg'));

        expect(wouldWarn()).toBe(true);
    });

    it('does not warn for a halted queue: the banner sends staff to a reload', async () => {
        const island = mounted();
        uploadFile.mockRejectedValueOnce(new SessionGone());

        await dropFiles(island, new File(['x'], 'a.jpg'));
        await flushPromises();

        expect(wouldWarn()).toBe(false);
    });

    it('warns before leaving with unsaved text', async () => {
        const island = mounted();

        await tile(island, 0).trigger('click');

        expect(wouldWarn()).toBe(false);

        await island.get('#photo-alt-de').setValue('Neu');

        expect(wouldWarn()).toBe(true);
    });
});
