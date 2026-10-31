import { afterEach, describe, expect, it, vi } from 'vitest';
import { createWrites, freshToken } from '../../src/cms/writes.js';

function answers(...responses) {
    const pending = [];
    const fetchMock = vi.fn(() => new Promise((resolve) => {
        pending.push(resolve);
    }));

    vi.stubGlobal('fetch', fetchMock);

    return {
        fetchMock,
        release: async () => {
            pending.shift()(responses.shift());
            await new Promise((resolve) => {
                setTimeout(resolve);
            });
        },
    };
}

const list = (photos) => new Response(JSON.stringify({ photos }), { status: 200, headers: { 'Content-Type': 'application/json' } });

const tick = () => new Promise((resolve) => {
    setTimeout(resolve);
});

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

describe('createWrites', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sends one write at a time and applies only the last answer', async () => {
        const apply = vi.fn();
        const writes = createWrites('photos', apply);
        const { fetchMock, release } = answers(list(['first']), list(['second']));

        writes.send('/one', { method: 'PUT', body: {} });
        writes.send('/two', { method: 'PUT', body: {} });
        await new Promise((resolve) => {
            setTimeout(resolve);
        });

        expect(fetchMock).toHaveBeenCalledOnce();

        await release();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(apply).not.toHaveBeenCalled();

        await release();

        expect(apply.mock.calls).toEqual([[['second']]]);
    });

    it('goes on after a failed write and applies null for a failure with nothing newer', async () => {
        const apply = vi.fn();
        const writes = createWrites('photos', apply);
        const { release } = answers(new Response('', { status: 500 }));

        const failure = writes.send('/one', { method: 'PUT', body: {} }).catch((error) => error.status);
        await new Promise((resolve) => {
            setTimeout(resolve);
        });
        await release();

        expect(await failure).toBe(500);
        expect(apply.mock.calls).toEqual([[null]]);
    });

    it('keeps an upload\'s list while a write waits, and sends that write only after the upload', async () => {
        const apply = vi.fn();
        const writes = createWrites('photos', apply);
        const upload = deferred();
        const { fetchMock, release } = answers(new Response('', { status: 500 }));

        writes.run(() => upload.promise);
        const failure = writes.send('/one', { method: 'PUT', body: {} }).catch(() => {});
        await tick();

        expect(fetchMock).not.toHaveBeenCalled();

        upload.resolve({ photos: ['uploaded'] });
        await tick();

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(apply).not.toHaveBeenCalled();

        await release();
        await failure;

        expect(apply.mock.calls).toEqual([[['uploaded']]]);
    });

    it('starts an upload only after the write before it, so the newest list is the last to arrive', async () => {
        const apply = vi.fn();
        const writes = createWrites('photos', apply);
        const work = vi.fn(async () => ({ photos: ['with the upload'] }));
        const { release } = answers(list(['read before it']));

        writes.send('/one', { method: 'PUT', body: {} });
        writes.run(work);
        await tick();

        expect(work).not.toHaveBeenCalled();

        await release();

        expect(work).toHaveBeenCalledOnce();
        expect(apply.mock.calls).toEqual([[['with the upload']]]);
    });

    it('goes on after a failed upload', async () => {
        const writes = createWrites('photos', vi.fn());
        const { fetchMock } = answers();

        writes.run(() => Promise.reject(new Error('no'))).catch(() => {});
        writes.send('/one', { method: 'PUT', body: {} });
        await tick();

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('builds a request when it leaves, from the list held by then', async () => {
        const writes = createWrites('photos', vi.fn());
        const upload = deferred();
        const { fetchMock } = answers();
        const options = vi.fn((held) => ({ method: 'PUT', body: { held } }));

        writes.run(() => upload.promise);
        writes.send('/one', options);
        await tick();

        expect(options).not.toHaveBeenCalled();

        upload.resolve({ photos: ['uploaded'] });
        await tick();

        expect(options.mock.calls).toEqual([[['uploaded']]]);
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ held: ['uploaded'] });
    });

    it('builds a request with null when nothing is held', async () => {
        const writes = createWrites('photos', vi.fn());
        const options = vi.fn(() => ({ method: 'PUT', body: {} }));

        answers();
        writes.send('/one', options);
        await tick();

        expect(options.mock.calls).toEqual([[null]]);
    });
});

describe('freshToken', () => {
    it('reads the row from the list, and keeps the old token for a row that left it', () => {
        expect(freshToken([{ id: 'a', token: 'new' }], { id: 'a', token: 'old' })).toBe('new');
        expect(freshToken([], { id: 'a', token: 'old' })).toBe('old');
    });
});
