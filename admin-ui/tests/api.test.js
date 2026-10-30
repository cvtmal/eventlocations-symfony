import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { adminFetch, RequestFailed, SessionGone, ValidationFailed } from '../src/api.js';

function answer(status, { type = 'application/json', body = '{}', responseType = 'basic' } = {}) {
    const response = new Response(status === 204 ? null : body, { status, headers: type ? { 'Content-Type': type } : {} });

    return Object.defineProperty(response, 'type', { value: responseType });
}

const opaqueRedirect = () => Object.defineProperties(new Response(null, { status: 200 }), {
    type: { value: 'opaqueredirect' },
    status: { value: 0 },
    ok: { value: false },
});

describe('adminFetch', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="token-1">';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    const stub = (response) => {
        const fetchMock = vi.fn(async () => response);
        vi.stubGlobal('fetch', fetchMock);

        return fetchMock;
    };

    it('sends the JSON headers and never follows a redirect', async () => {
        const fetchMock = stub(answer(200, { body: '{"ok":true}' }));

        await expect(adminFetch('/admin/api/x')).resolves.toEqual({ ok: true });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/admin/api/x');
        expect(init.method).toBe('GET');
        expect(init.redirect).toBe('manual');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-CSRF-Token']).toBe('token-1');
        expect(init.headers['Content-Type']).toBeUndefined();
    });

    it('sends a body as JSON', async () => {
        const fetchMock = stub(answer(200));

        await adminFetch('/admin/api/x', { method: 'POST', body: { name: 'See' } });

        const init = fetchMock.mock.calls[0][1];
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"name":"See"}');
        expect(init.headers['Content-Type']).toBe('application/json');
    });

    it('leaves a FormData body to the browser, which sets the boundary', async () => {
        const fetchMock = stub(answer(200));
        const form = new FormData();

        await adminFetch('/admin/api/x', { method: 'POST', body: form });

        expect(fetchMock.mock.calls[0][1].body).toBe(form);
        expect(fetchMock.mock.calls[0][1].headers['Content-Type']).toBeUndefined();
    });

    it('treats an opaque redirect as session gone', async () => {
        stub(opaqueRedirect());

        await expect(adminFetch('/x')).rejects.toBeInstanceOf(SessionGone);
    });

    it('treats a 3xx as session gone', async () => {
        stub(answer(302, { type: 'text/html', body: '' }));

        await expect(adminFetch('/x')).rejects.toBeInstanceOf(SessionGone);
    });

    it('treats a 401 as session gone', async () => {
        stub(answer(401, { body: '{"error":"x"}' }));

        await expect(adminFetch('/x')).rejects.toBeInstanceOf(SessionGone);
    });

    it('answers a 204 with null', async () => {
        stub(answer(204, { type: null }));

        await expect(adminFetch('/x')).resolves.toBeNull();
    });

    it('treats a successful non-JSON answer as session gone: a login page arrived', async () => {
        stub(answer(200, { type: 'text/html', body: '<html>Anmelden</html>' }));

        await expect(adminFetch('/x')).rejects.toBeInstanceOf(SessionGone);
    });

    it('throws the field errors of a 422', async () => {
        stub(answer(422, { body: '{"errors":{"name":["Fehlt"]}}' }));

        const error = await adminFetch('/x').catch((caught) => caught);

        expect(error).toBeInstanceOf(ValidationFailed);
        expect(error.errors).toEqual({ name: ['Fehlt'] });
    });

    it.each([
        ['403 JSON', 403, 'application/json'],
        ['413 HTML', 413, 'text/html'],
        ['422 HTML', 422, 'text/html'],
        ['500 HTML', 500, 'text/html'],
        ['500 JSON', 500, 'application/json'],
        ['504 HTML', 504, 'text/html'],
    ])('keeps the session on a %s and reports the status', async (name, status, type) => {
        stub(answer(status, { type, body: '<html>Fehler</html>' }));

        const error = await adminFetch('/x').catch((caught) => caught);

        expect(error).toBeInstanceOf(RequestFailed);
        expect(error).not.toBeInstanceOf(SessionGone);
        expect(error.status).toBe(status);
    });

    it('reports a network failure as a failed request', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        }));

        const error = await adminFetch('/x').catch((caught) => caught);

        expect(error).toBeInstanceOf(RequestFailed);
        expect(error.status).toBe(0);
    });

    it('rethrows an abort as it is', async () => {
        const abort = new DOMException('Aborted', 'AbortError');
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw abort;
        }));

        await expect(adminFetch('/x', { signal: new AbortController().signal })).rejects.toBe(abort);
    });
});
