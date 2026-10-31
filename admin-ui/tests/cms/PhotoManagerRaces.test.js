import { enableAutoUnmount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { dropFiles, json, mounted, photo, tile } from './photoManagerFixture.js';

enableAutoUnmount(afterEach);

// An upload and a write through the real uploadFile and adminFetch, against a stand-in for the real stack: PHP's
// session lock makes a request wait for a running upload, and the set check of a reorder then compares with the
// gallery as that upload left it. Unserialised, the log reads POST, PUT, 201, 409, as in the review (F-M1).
function fakeServer(initial) {
    const server = {
        photos: initial,
        log: [],
        bodies: [],
        upload: null,
        blocked: [],
        finishUpload(added) {
            const xhr = server.upload;

            server.photos = [...server.photos, added];
            server.upload = null;
            server.log.push('201');
            xhr.respond(201, { duplicate: false, photos: server.photos });
            server.blocked.splice(0).forEach((answer) => answer());
        },
        failUpload() {
            const xhr = server.upload;

            server.upload = null;
            server.log.push('500');
            xhr.respond(500, {});
            server.blocked.splice(0).forEach((answer) => answer());
        },
    };

    class Xhr {
        upload = {};

        responseURL = '';

        responseText = '';

        status = 0;

        open() {}

        setRequestHeader() {}

        getResponseHeader() {
            return 'application/json';
        }

        send() {
            server.log.push('POST');
            server.upload = this;
        }

        respond(status, body) {
            Object.assign(this, { status, responseText: JSON.stringify(body) });
            this.onload();
        }
    }

    function reorder({ roomId, ids }) {
        const scope = server.photos.filter((row) => row.roomId === roomId).map(({ id }) => id);

        if ([...ids].sort().join() !== [...scope].sort().join()) {
            return json({ errors: { '': ['Stale'] } }, 409);
        }

        server.photos = server.photos.map((row) => {
            const position = ids.indexOf(row.id) + 1;

            return position === 0 || position === row.position ? row : { ...row, position, token: `moved-${row.id}` };
        });

        return json({ photos: server.photos });
    }

    function saveAlt(url, { loadedAt, alts }) {
        const id = url.split('/').at(-2);

        if (server.photos.find((row) => row.id === id).token !== loadedAt) {
            return json({ errors: { '': ['Stale'] } }, 409);
        }

        server.photos = server.photos.map((row) => (row.id === id ? { ...row, alts, token: `saved-${id}` } : row));

        return json({ photos: server.photos });
    }

    const fetchStub = (url, init) => new Promise((resolve) => {
        const body = JSON.parse(init.body);

        server.log.push(`PUT ${url}`);
        server.bodies.push(body);

        const answer = () => {
            const response = url.endsWith('/order') ? reorder(body) : saveAlt(url, body);

            server.log.push(String(response.status));
            resolve(response);
        };

        if (server.upload) {
            server.blocked.push(answer);
        } else {
            answer();
        }
    });

    vi.stubGlobal('XMLHttpRequest', Xhr);
    vi.stubGlobal('fetch', fetchStub);

    return server;
}

const settled = async () => {
    await flushPromises();
    await flushPromises();
    await flushPromises();
};

const shown = (island) => island.findAll('.photo-tile img').map((image) => image.attributes('src').match(/1\/(\w+)-/)[1]);

describe('PhotoManagerIsland, an upload beside a write', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="token-1">';
        document.body.innerHTML = '<div class="toast-region"></div>';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    it('holds a reorder until the upload has answered, then sends the new photo along', async () => {
        const server = fakeServer([photo('a', 1), photo('b', 2), photo('c', 3)]);
        const island = mounted(server.photos);

        await dropFiles(island, new File(['x'], 'd.jpg'));
        await settled();
        await island.get('[data-move="up-b"]').trigger('click');
        await settled();

        expect(server.log).toEqual(['POST']);
        expect(shown(island)).toEqual(['b', 'a', 'c']);
        expect(island.get('[data-move="up-a"]').attributes('aria-disabled')).toBeUndefined();

        server.finishUpload(photo('d', 4));
        await settled();

        expect(server.log).toEqual(['POST', '201', 'PUT /api/photos/order', '200']);
        expect(server.bodies).toEqual([{ roomId: null, ids: ['b', 'a', 'c', 'd'] }]);
        expect(island.find('[role="alert"]').exists()).toBe(false);
        expect(shown(island)).toEqual(['b', 'a', 'c', 'd']);
    });

    it('takes every move during an upload, and the next upload waits for them', async () => {
        const server = fakeServer([photo('a', 1), photo('b', 2), photo('c', 3)]);
        const island = mounted(server.photos);

        await dropFiles(island, new File(['x'], 'd.jpg'), new File(['x'], 'e.jpg'));
        await settled();
        await island.get('[data-move="up-b"]').trigger('click');
        await island.get('[data-move="down-a"]').trigger('click');
        await settled();

        server.finishUpload(photo('d', 4));
        await settled();

        expect(server.log).toEqual(['POST', '201', 'PUT /api/photos/order', '200', 'PUT /api/photos/order', '200', 'POST']);
        expect(server.bodies.map(({ ids }) => ids)).toEqual([['b', 'a', 'c', 'd'], ['b', 'c', 'a', 'd']]);

        server.finishUpload(photo('e', 5));
        await settled();

        expect(shown(island)).toEqual(['b', 'c', 'a', 'd', 'e']);
        expect(island.find('[role="alert"]').exists()).toBe(false);
    });

    it('sends a held move after a failed upload, without the file that failed', async () => {
        const server = fakeServer([photo('a', 1), photo('b', 2), photo('c', 3)]);
        const island = mounted(server.photos);

        await dropFiles(island, new File(['x'], 'd.jpg'));
        await settled();
        await island.get('[data-move="up-b"]').trigger('click');
        await settled();

        server.failUpload();
        await settled();

        expect(server.bodies).toEqual([{ roomId: null, ids: ['b', 'a', 'c'] }]);
        expect(island.get('.photo-queue__state').text()).toBe('queueFailed');
        expect(shown(island)).toEqual(['b', 'a', 'c']);
    });

    it('holds a save behind a held reorder and sends the token that reorder left', async () => {
        const server = fakeServer([photo('a', 1), photo('b', 2), photo('c', 3)]);
        const island = mounted(server.photos);

        await dropFiles(island, new File(['x'], 'd.jpg'));
        await settled();
        await tile(island, 0).trigger('click');
        await island.get('#photo-alt-de').setValue('Neu');
        await island.get('[data-move="down-a"]').trigger('click');
        await island.get('.photo-panel .btn--primary').trigger('click');
        await settled();

        expect(server.log).toEqual(['POST']);

        server.finishUpload(photo('d', 4));
        await settled();

        expect(server.log).toEqual(['POST', '201', 'PUT /api/photos/order', '200', 'PUT /api/photos/a/alt', '200']);
        expect(server.bodies[1].loadedAt).toBe('moved-a');
        expect(island.find('[role="alert"]').exists()).toBe(false);
        expect(document.querySelector('.toast--success').textContent).toContain('saved');
    });

    it('drops a photo removed meanwhile from a held order, not the order itself', async () => {
        const server = fakeServer([photo('a', 1), photo('b', 2), photo('c', 3)]);
        const island = mounted(server.photos);

        await dropFiles(island, new File(['x'], 'd.jpg'));
        await settled();
        await island.get('[data-move="up-c"]').trigger('click');
        await settled();

        server.photos = server.photos.filter(({ id }) => id !== 'b');
        server.finishUpload(photo('d', 3));
        await settled();

        expect(server.bodies).toEqual([{ roomId: null, ids: ['a', 'c', 'd'] }]);
        expect(island.find('[role="alert"]').exists()).toBe(false);
    });
});
