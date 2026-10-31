import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RequestFailed, SessionGone, ValidationFailed } from '../../src/api.js';
import { uploadFile } from '../../src/cms/upload.js';

class FakeXhr {
    static last = null;

    upload = {};

    requestHeaders = {};

    responseHeaders = {};

    responseURL = '';

    responseText = '';

    status = 0;

    open(method, url) {
        Object.assign(this, { method, url });
    }

    setRequestHeader(name, value) {
        this.requestHeaders[name] = value;
    }

    getResponseHeader(name) {
        return this.responseHeaders[name] ?? null;
    }

    send(body) {
        this.body = body;
        FakeXhr.last = this;
    }

    abort() {
        this.onabort();
    }

    respond(status, { type = 'application/json', body = '{}', url = 'http://localhost:3000/upload' } = {}) {
        Object.assign(this, { status, responseText: body, responseURL: url, responseHeaders: type ? { 'Content-Type': type } : {} });
        this.onload();
    }
}

const file = new File(['bytes'], 'seesaal.jpg', { type: 'image/jpeg' });

describe('uploadFile', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="token-1">';
        vi.stubGlobal('XMLHttpRequest', FakeXhr);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the file with the JSON headers', async () => {
        const answer = uploadFile('/upload', file, { fields: { roomId: 'r1' } });

        const xhr = FakeXhr.last;
        expect(xhr.method).toBe('POST');
        expect(xhr.url).toBe('/upload');
        expect(xhr.requestHeaders.Accept).toBe('application/json');
        expect(xhr.requestHeaders['X-CSRF-Token']).toBe('token-1');
        expect(xhr.requestHeaders['Content-Type']).toBeUndefined();
        expect(xhr.body.get('file')).toBeInstanceOf(File);
        expect(xhr.body.get('roomId')).toBe('r1');

        xhr.respond(201, { body: '{"ok":true}' });
        await expect(answer).resolves.toEqual({ ok: true });
    });

    it('reports the bytes, then the processing phase', () => {
        const onProgress = vi.fn();

        uploadFile('/upload', file, { onProgress });
        FakeXhr.last.upload.onprogress({ loaded: 5, total: 10 });
        FakeXhr.last.upload.onload();

        expect(onProgress.mock.calls.map(([event]) => event)).toEqual([
            { phase: 'sending', loaded: 5, total: 10 },
            { phase: 'processing' },
        ]);
    });

    it.each([
        ['a followed redirect', 200, { url: 'http://localhost:3000/admin/login' }],
        ['a 3xx', 302, { type: 'text/html', body: '' }],
        ['a 401', 401, { body: '{"error":"x"}' }],
    ])('treats %s as session gone', async (_, status, options) => {
        const answer = uploadFile('/upload', file);

        FakeXhr.last.respond(status, options);

        await expect(answer).rejects.toBeInstanceOf(SessionGone);
    });

    it('answers a 204 with null', async () => {
        const answer = uploadFile('/upload', file);

        FakeXhr.last.respond(204, { type: null, body: '' });

        await expect(answer).resolves.toBeNull();
    });

    it('throws the field errors of a 422', async () => {
        const answer = uploadFile('/upload', file);

        FakeXhr.last.respond(422, { body: '{"errors":{"file":["Fehlt"]}}' });

        const error = await answer.catch((caught) => caught);
        expect(error).toBeInstanceOf(ValidationFailed);
        expect(error.errors).toEqual({ file: ['Fehlt'] });
    });

    it.each([
        ['200 HTML: a PHP fatal under display_errors, no redirect', 200, 'text/html'],
        ['403 JSON', 403, 'application/json'],
        ['413 HTML', 413, 'text/html'],
        ['422 HTML', 422, 'text/html'],
        ['500 HTML', 500, 'text/html'],
        ['500 JSON', 500, 'application/json'],
        ['504 HTML', 504, 'text/html'],
    ])('keeps the session on a %s', async (_, status, type) => {
        const answer = uploadFile('/upload', file);

        FakeXhr.last.respond(status, { type, body: 'x' });

        const error = await answer.catch((caught) => caught);
        expect(error).toBeInstanceOf(RequestFailed);
        expect(error.status).toBe(status);
    });

    it('treats unreadable JSON as a failed request', async () => {
        const answer = uploadFile('/upload', file);

        FakeXhr.last.respond(200, { body: '<not json' });

        const error = await answer.catch((caught) => caught);
        expect(error).toBeInstanceOf(RequestFailed);
        expect(error.status).toBe(200);
    });

    it('reports a network failure as status 0', async () => {
        const answer = uploadFile('/upload', file);

        FakeXhr.last.onerror();

        const error = await answer.catch((caught) => caught);
        expect(error).toBeInstanceOf(RequestFailed);
        expect(error.status).toBe(0);
    });

    it('rejects with an AbortError when the signal aborts', async () => {
        const controller = new AbortController();
        const answer = uploadFile('/upload', file, { signal: controller.signal });

        controller.abort();

        await expect(answer).rejects.toMatchObject({ name: 'AbortError' });
    });
});
