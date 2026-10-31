import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RequestFailed, SessionGone, ValidationFailed } from '../../src/api.js';
import { createQueue } from '../../src/cms/queue.js';

const MAX = 25_000_000;

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

const flush = async () => {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
};

function setUp() {
    const calls = [];
    const state = { items: [], halted: false };
    const queue = createQueue({
        maxBytes: MAX,
        hideDoneAfterMs: 1000,
        upload: (file, onProgress) => {
            const call = { ...deferred(), file, onProgress };
            calls.push(call);

            return call.promise;
        },
        onChange: ({ items, halted }) => Object.assign(state, { items, halted }),
    });

    return { calls, state, queue };
}

const photo = (name, size = 10) => ({ name, size });

describe('createQueue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('starts the second upload only after the first settled', async () => {
        const { calls, state, queue } = setUp();

        queue.add([photo('a.jpg'), photo('b.jpg')]);

        expect(calls.map(({ file }) => file.name)).toEqual(['a.jpg']);
        expect(state.items.map((item) => item.state)).toEqual(['sending', 'waiting']);

        calls[0].resolve({ photos: [], duplicate: false });
        await flush();

        expect(calls.map(({ file }) => file.name)).toEqual(['a.jpg', 'b.jpg']);
        expect(state.items.map((item) => item.state)).toEqual(['done', 'sending']);
    });

    it('follows the phases of an upload', () => {
        const { calls, state, queue } = setUp();

        queue.add([photo('a.jpg')]);
        calls[0].onProgress({ phase: 'sending', loaded: 4, total: 10 });

        expect(state.items[0]).toMatchObject({ state: 'sending', loaded: 4, total: 10 });

        calls[0].onProgress({ phase: 'processing' });

        expect(state.items[0].state).toBe('processing');
        expect(queue.running()).toBe(true);
    });

    it('refuses a file above the limit with no request', () => {
        const { calls, state, queue } = setUp();

        queue.add([photo('huge.jpg', 26_000_000)]);

        expect(calls).toHaveLength(0);
        expect(state.items[0]).toMatchObject({ state: 'refused', reason: 'tooLarge' });
        expect(queue.running()).toBe(false);
    });

    it('accepts a file of exactly the limit', () => {
        const { calls, queue } = setUp();

        queue.add([photo('edge.jpg', MAX)]);

        expect(calls).toHaveLength(1);
    });

    it('shows the server message of a 422 and goes on', async () => {
        const { calls, state, queue } = setUp();

        queue.add([photo('menu.pdf'), photo('b.jpg')]);
        calls[0].reject(new ValidationFailed({ file: ['Keine lesbare Bilddatei.'] }));
        await flush();

        expect(state.items[0]).toMatchObject({ state: 'refused', message: 'Keine lesbare Bilddatei.' });
        expect(calls).toHaveLength(2);
    });

    it('keeps a file retryable after a 503 and goes on with the next', async () => {
        const { calls, queue, state } = setUp();

        queue.add([photo('a.jpg'), photo('b.jpg')]);
        calls[0].reject(new RequestFailed(503));
        await flush();

        expect(state.halted).toBe(false);
        expect(state.items.map(({ state: item }) => item)).toEqual(['failed', 'sending']);
        expect(calls).toHaveLength(2);

        calls[1].resolve({ duplicate: false });
        await flush();
        queue.retry(state.items[0].id);
        await flush();

        expect(calls).toHaveLength(3);
        expect(calls[2].file.name).toBe('a.jpg');
    });

    it('takes a 413 as too large, not as a lost session', async () => {
        const { calls, state, queue } = setUp();

        queue.add([photo('a.jpg')]);
        calls[0].reject(new RequestFailed(413));
        await flush();

        expect(state.items[0]).toMatchObject({ state: 'refused', reason: 'tooLarge' });
        expect(state.halted).toBe(false);
    });

    it.each([[500], [504], [0]])('offers a retry after status %i', async (status) => {
        const { calls, state, queue } = setUp();

        queue.add([photo('a.jpg')]);
        calls[0].reject(new RequestFailed(status));
        await flush();

        expect(state.items[0].state).toBe('failed');

        queue.retry(state.items[0].id);

        expect(calls).toHaveLength(2);
        expect(state.items[0].state).toBe('sending');
    });

    it.each([
        ['a lost session', new SessionGone()],
        ['a dead token', new RequestFailed(403)],
    ])('stops for good at %s and keeps the rest listed as waiting', async (_, error) => {
        const { calls, state, queue } = setUp();

        queue.add([photo('a.jpg'), photo('b.jpg')]);
        calls[0].reject(error);
        await flush();

        expect(state.halted).toBe(true);
        expect(state.items.map((item) => item.state)).toEqual(['waiting', 'waiting']);
        expect(calls).toHaveLength(1);

        queue.add([photo('c.jpg')]);

        expect(state.items.map((item) => item.name)).toEqual(['a.jpg', 'b.jpg', 'c.jpg']);
        expect(calls).toHaveLength(1);
        expect(queue.running()).toBe(false);
    });

    it('marks a duplicate and hides a finished upload', async () => {
        const { calls, state, queue } = setUp();

        queue.add([photo('a.jpg')]);
        calls[0].resolve({ photos: [], duplicate: true });
        await flush();

        expect(state.items[0]).toMatchObject({ state: 'done', duplicate: true });

        vi.advanceTimersByTime(1000);

        expect(state.items).toEqual([]);
    });

    it('dismisses a refused file', () => {
        const { state, queue } = setUp();

        queue.add([photo('huge.jpg', MAX + 1)]);
        queue.dismiss(state.items[0].id);

        expect(state.items).toEqual([]);
    });
});
