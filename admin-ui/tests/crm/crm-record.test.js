import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

const card = (text) => `<section data-crm-refresh="company"><p>${text}</p></section>`;

function saved(name = 'company') {
    document.dispatchEvent(new CustomEvent('crm:property-saved', { detail: { name, value: 'co-2', display: 'Neue Firma' } }));
}

function page(body, status = 200) {
    return new Response(`<!doctype html><body>${body}</body>`, { status, headers: { 'Content-Type': 'text/html' } });
}

const settled = () => new Promise((resolve) => { setTimeout(resolve); });

describe('crm-record.js', () => {
    beforeAll(async () => {
        await import('../../../assets/js/admin/crm-record.js');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    it('replaces the card from a fresh page and leaves text typed in other rows', async () => {
        document.body.innerHTML = `${card('Alte Firma')}<input id="phone" value="getippt">`;
        document.querySelector('#phone').focus();
        const fetch = vi.fn(async () => page(card('Neue Firma')));
        vi.stubGlobal('fetch', fetch);

        saved();
        await settled();

        expect(fetch).toHaveBeenCalledOnce();
        expect(document.querySelector('[data-crm-refresh]').textContent).toBe('Neue Firma');
        expect(document.querySelector('#phone').value).toBe('getippt');
        expect(document.activeElement.id).toBe('phone');
    });

    it('asks for nothing on a field without a card', async () => {
        document.body.innerHTML = card('Alte Firma');
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);

        saved('phone');
        await settled();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('takes the newest save when two answers cross', async () => {
        document.body.innerHTML = card('Alte Firma');
        let first;
        const fetch = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => {
                first = () => resolve(page(card('Veraltet')));
            }))
            .mockImplementationOnce(async () => page(card('Neueste')));
        vi.stubGlobal('fetch', fetch);

        saved();
        saved();
        await settled();
        first();
        await settled();

        expect(document.querySelector('[data-crm-refresh]').textContent).toBe('Neueste');
    });

    it.each([
        ['a failed answer', async () => page(card('Fehlerseite'), 500)],
        ['a redirect to the login', async () => Response.error()],
        ['a lost connection', async () => { throw new TypeError('Failed to fetch'); }],
    ])('keeps the card on %s', async (name, answer) => {
        document.body.innerHTML = card('Alte Firma');
        vi.stubGlobal('fetch', vi.fn(answer));

        saved();
        await settled();

        expect(document.querySelector('[data-crm-refresh]').textContent).toBe('Alte Firma');
    });
});
