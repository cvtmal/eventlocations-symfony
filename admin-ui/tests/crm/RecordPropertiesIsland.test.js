import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RecordPropertiesIsland from '../../src/islands/RecordPropertiesIsland.vue';

const labels = {
    edit: 'Bearbeiten',
    save: 'Speichern',
    cancel: 'Abbrechen',
    empty: '—',
    none: 'Keine Angabe',
    stale: 'Dieses Feld wurde inzwischen geändert.',
    notFound: 'Dieser Eintrag existiert nicht mehr.',
    reload: 'Seite neu laden',
    sessionGone: 'Sitzung abgelaufen.',
    error: 'Speichern nicht möglich.',
    noResults: 'Keine Treffer.',
    choose: 'Bitte einen Eintrag aus der Liste wählen.',
};

const fields = [
    { name: 'phone', label: 'Telefon', type: 'tel', value: '+41 44 000 00 00' },
    { name: 'notes', label: 'Notizen', type: 'textarea', value: null },
    {
        name: 'stage',
        label: 'Phase',
        type: 'select',
        value: 'prospect',
        required: true,
        options: [{ value: 'prospect', label: 'Interessent' }, { value: 'customer', label: 'Kunde' }],
    },
];

function json(body, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function island() {
    return mount(RecordPropertiesIsland, { props: { url: '/admin/api/crm/companies/c1', fields, labels }, attachTo: document.body });
}

function companyIsland() {
    return mount(RecordPropertiesIsland, {
        props: {
            url: '/admin/api/crm/contacts/k1',
            fields: [{ name: 'company', label: 'Firma', type: 'combobox', value: 'co-1', display: 'Alte Firma', lookupUrl: '/admin/api/crm/companies' }],
            labels,
        },
        attachTo: document.body,
    });
}

const cancelButton = (wrapper, name) => row(wrapper, name).findAll('button').find((button) => button.text() === 'Abbrechen');
const saveButton = (wrapper, name) => row(wrapper, name).findAll('button').find((button) => button.text() === 'Speichern');

const row = (wrapper, name) => wrapper.get(`[data-property="${name}"]`);

async function editAndSave(wrapper, name, text) {
    await row(wrapper, name).get('.property__edit').trigger('click');
    await row(wrapper, name).get('input, textarea, select').setValue(text);
    await row(wrapper, name).get('form').trigger('submit');
    await flushPromises();
}

// The bodies of the PATCH requests, in order (a combobox lookup is a GET).
function sentBodies(fetch) {
    return fetch.mock.calls.filter(([, init]) => init.method === 'PATCH').map(([, init]) => JSON.parse(init.body));
}

describe('RecordPropertiesIsland', () => {
    let wrapper;

    afterEach(() => {
        wrapper?.unmount();
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    it('shows each value, and the empty label for an empty one', () => {
        wrapper = island();

        expect(row(wrapper, 'phone').get('.property__value').text()).toBe('+41 44 000 00 00');
        expect(row(wrapper, 'notes').get('.property__value').text()).toBe('—');
        expect(row(wrapper, 'stage').get('.property__value').text()).toBe('Interessent');
    });

    it('saves one field with a PATCH and shows the canonical answer', async () => {
        const fetch = vi.fn(() => Promise.resolve(json({ value: '+41 44 111 11 11' })));
        vi.stubGlobal('fetch', fetch);
        wrapper = island();
        const saved = vi.fn();
        wrapper.element.addEventListener('crm:property-saved', (event) => saved(event.detail));

        await editAndSave(wrapper, 'phone', ' +41 44 111 11 11 ');

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toBe('/admin/api/crm/companies/c1');
        expect(fetch.mock.calls[0][1].method).toBe('PATCH');
        expect(sentBodies(fetch)[0]).toEqual({ field: 'phone', value: ' +41 44 111 11 11 ', expected: '+41 44 000 00 00' });
        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
        expect(row(wrapper, 'phone').get('.property__value').text()).toBe('+41 44 111 11 11');
        expect(document.activeElement).toBe(row(wrapper, 'phone').get('.property__edit').element);
        expect(saved).toHaveBeenCalledWith({ name: 'phone', value: '+41 44 111 11 11', display: '+41 44 111 11 11' });
    });

    it('sends the value of the last answer as expected', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(json({ value: 'Erste Notiz' }))
            .mockResolvedValueOnce(json({ value: 'Zweite Notiz' }));
        vi.stubGlobal('fetch', fetch);
        wrapper = island();

        await editAndSave(wrapper, 'notes', 'Erste Notiz  ');
        await editAndSave(wrapper, 'notes', 'Zweite Notiz');

        expect(sentBodies(fetch).map((body) => body.expected)).toEqual([null, 'Erste Notiz']);
    });

    it('shows a select by its option label', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(json({ value: 'customer' }))));
        wrapper = island();

        await editAndSave(wrapper, 'stage', 'customer');

        expect(row(wrapper, 'stage').get('.property__value').text()).toBe('Kunde');
    });

    it('cancels with Abbrechen and with Esc, sending nothing', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        wrapper = island();

        await row(wrapper, 'phone').get('.property__edit').trigger('click');
        expect(document.activeElement).toBe(row(wrapper, 'phone').get('input').element);
        await row(wrapper, 'phone').get('input').setValue('falsch');
        await row(wrapper, 'phone').findAll('button').find((button) => button.text() === 'Abbrechen').trigger('click');

        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
        expect(row(wrapper, 'phone').get('.property__value').text()).toBe('+41 44 000 00 00');

        await row(wrapper, 'phone').get('.property__edit').trigger('click');
        await row(wrapper, 'phone').get('input').trigger('keydown', { key: 'Escape' });

        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
        expect(document.activeElement).toBe(row(wrapper, 'phone').get('.property__edit').element);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows a 422 message under the field and keeps the input', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(json({ errors: { value: ['Bitte eine Telefonnummer eingeben.'] } }, 422))));
        wrapper = island();

        await editAndSave(wrapper, 'phone', 'ruf an');

        const input = row(wrapper, 'phone').get('input');
        const error = row(wrapper, 'phone').get('.field__error');
        expect(error.text()).toBe('Bitte eine Telefonnummer eingeben.');
        expect(input.element.value).toBe('ruf an');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe(error.attributes('id'));
        expect(error.find('a').exists()).toBe(false);
    });

    it('shows the stale label and a reload link on a 409', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(json({ errors: { '': ['stale'] } }, 409))));
        wrapper = island();

        await editAndSave(wrapper, 'phone', '+41 44 222 22 22');

        const error = row(wrapper, 'phone').get('.field__error');
        expect(error.text()).toContain(labels.stale);
        expect(error.get('a').text()).toBe(labels.reload);
        expect(error.get('a').attributes('href')).toBe(window.location.href.split('#')[0]);
        expect(row(wrapper, 'phone').get('input').element.value).toBe('+41 44 222 22 22');
    });

    it('says the session is gone on a redirect or a 401', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('', { status: 401 }))));
        wrapper = island();

        await editAndSave(wrapper, 'phone', '+41 44 222 22 22');

        const error = row(wrapper, 'phone').get('.field__error');
        expect(error.text()).toContain(labels.sessionGone);
        expect(error.find('a').exists()).toBe(true);
    });

    it('keeps the old value as expected after a failure', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(new Response('', { status: 500 }))
            .mockResolvedValueOnce(json({ value: '+41 44 333 33 33' }));
        vi.stubGlobal('fetch', fetch);
        wrapper = island();

        await editAndSave(wrapper, 'phone', '+41 44 333 33 33');
        expect(row(wrapper, 'phone').get('.field__error').text()).toBe(labels.error);
        await row(wrapper, 'phone').get('form').trigger('submit');
        await flushPromises();

        expect(sentBodies(fetch).map((body) => body.expected)).toEqual(['+41 44 000 00 00', '+41 44 000 00 00']);
        expect(row(wrapper, 'phone').get('.property__value').text()).toBe('+41 44 333 33 33');
    });

    it('sends the hidden id of a combobox and shows its label', async () => {
        const fetch = vi.fn((url) => Promise.resolve(url.startsWith('/admin/api/crm/companies?')
            ? json({ options: [{ value: 'co-2', label: 'Seeblick Events', detail: null }] })
            : json({ value: 'co-2' })));
        vi.stubGlobal('fetch', fetch);
        vi.useFakeTimers();
        wrapper = companyIsland();

        await row(wrapper, 'company').get('.property__edit').trigger('click');
        await row(wrapper, 'company').get('[role="combobox"]').setValue('See');
        vi.advanceTimersByTime(150);
        await flushPromises();
        await row(wrapper, 'company').get('[role="option"]').trigger('mousedown');
        await row(wrapper, 'company').get('form').trigger('submit');
        await flushPromises();
        vi.useRealTimers();

        expect(sentBodies(fetch)).toEqual([
            { field: 'company', value: 'co-2', expected: 'co-1' },
        ]);
        expect(row(wrapper, 'company').get('.property__value').text()).toBe('Seeblick Events');
    });

    it('refuses text in a combobox with no option chosen, and sends nothing', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        wrapper = companyIsland();

        await row(wrapper, 'company').get('.property__edit').trigger('click');
        await row(wrapper, 'company').get('[role="combobox"]').setValue('Seeblick');
        await row(wrapper, 'company').get('form').trigger('submit');
        await flushPromises();

        const input = row(wrapper, 'company').get('[role="combobox"]');
        const error = row(wrapper, 'company').get('.field__error');
        expect(fetch).not.toHaveBeenCalled();
        expect(error.text()).toBe(labels.choose);
        expect(error.find('a').exists()).toBe(false);
        expect(input.element.value).toBe('Seeblick');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe(error.attributes('id'));
    });

    it('empties a combobox only when its text is empty', async () => {
        const fetch = vi.fn(() => Promise.resolve(json({ value: null })));
        vi.stubGlobal('fetch', fetch);
        wrapper = companyIsland();

        await row(wrapper, 'company').get('.property__edit').trigger('click');
        await row(wrapper, 'company').get('[role="combobox"]').setValue('  ');
        await row(wrapper, 'company').get('form').trigger('submit');
        await flushPromises();

        expect(sentBodies(fetch)).toEqual([{ field: 'company', value: '', expected: 'co-1' }]);
        expect(row(wrapper, 'company').get('.property__value').text()).toBe(labels.empty);
    });

    it('keeps an unchanged combobox value', async () => {
        const fetch = vi.fn(() => Promise.resolve(json({ value: 'co-1' })));
        vi.stubGlobal('fetch', fetch);
        wrapper = companyIsland();

        await row(wrapper, 'company').get('.property__edit').trigger('click');
        await row(wrapper, 'company').get('form').trigger('submit');
        await flushPromises();

        expect(sentBodies(fetch)).toEqual([{ field: 'company', value: 'co-1', expected: 'co-1' }]);
        expect(row(wrapper, 'company').get('.property__value').text()).toBe('Alte Firma');
    });

    it('names the listbox of a combobox after its field', async () => {
        wrapper = companyIsland();

        await row(wrapper, 'company').get('.property__edit').trigger('click');

        expect(row(wrapper, 'company').get('[role="listbox"]').attributes('aria-label')).toBe('Firma');
    });

    it('cannot be cancelled while a save is in flight', async () => {
        let answer;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            answer = resolve;
        })));
        wrapper = island();

        await row(wrapper, 'phone').get('.property__edit').trigger('click');
        await row(wrapper, 'phone').get('input').setValue('+41 44 444 44 44');
        await row(wrapper, 'phone').get('form').trigger('submit');
        await flushPromises();

        expect(saveButton(wrapper, 'phone').attributes('aria-busy')).toBe('true');
        expect(cancelButton(wrapper, 'phone').attributes('aria-disabled')).toBe('true');
        await cancelButton(wrapper, 'phone').trigger('click');
        await row(wrapper, 'phone').get('input').trigger('keydown', { key: 'Escape' });
        expect(row(wrapper, 'phone').find('form').exists()).toBe(true);

        answer(json({ value: '+41 44 444 44 44' }));
        await flushPromises();

        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
        expect(row(wrapper, 'phone').get('.property__value').text()).toBe('+41 44 444 44 44');
        expect(document.activeElement).toBe(row(wrapper, 'phone').get('.property__edit').element);
    });

    it('cancels again after a failed save', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('', { status: 500 }))));
        wrapper = island();

        await editAndSave(wrapper, 'phone', '+41 44 444 44 44');

        expect(saveButton(wrapper, 'phone').attributes('aria-busy')).toBeUndefined();
        expect(cancelButton(wrapper, 'phone').attributes('aria-disabled')).toBeUndefined();
        await cancelButton(wrapper, 'phone').trigger('click');
        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
    });

    it('does not announce a save that changed nothing', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json({ value: '+41 44 000 00 00' })));
        const wrapper = island();
        const announced = vi.fn();
        wrapper.element.addEventListener('crm:property-saved', announced);

        await editAndSave(wrapper, 'phone', '+41 44 000 00 00');

        expect(announced).not.toHaveBeenCalled();
        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
    });

    it('keeps text typed during its own save', async () => {
        let answer;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { answer = () => resolve(json({ value: '+41 44 111 11 11' })); })));
        const wrapper = island();
        await row(wrapper, 'phone').get('button').trigger('click');
        await row(wrapper, 'phone').get('input').setValue('+41 44 111 11 11');
        await row(wrapper, 'phone').get('form').trigger('submit');

        await row(wrapper, 'phone').get('input').setValue('+41 44 222 22 22');
        answer();
        await flushPromises();

        expect(row(wrapper, 'phone').get('input').element.value).toBe('+41 44 222 22 22');

        vi.stubGlobal('fetch', vi.fn(async () => json({ value: '+41 44 222 22 22' })));
        await row(wrapper, 'phone').get('form').trigger('submit');
        await flushPromises();

        expect(sentBodies(fetch)).toEqual([{ field: 'phone', value: '+41 44 222 22 22', expected: '+41 44 111 11 11' }]);
    });

    it('leaves a Unicode address alone', async () => {
        const wrapper = mount(RecordPropertiesIsland, {
            props: { url: '/admin/api/crm/contacts/k1', fields: [{ name: 'email', label: 'E-Mail', type: 'email', value: 'a@müller.ch' }], labels },
            attachTo: document.body,
        });

        await row(wrapper, 'email').get('button').trigger('click');

        const input = row(wrapper, 'email').get('input');
        expect(input.attributes('type')).toBe('text');
        expect(input.attributes('inputmode')).toBe('email');
    });

    it('lets Esc close an open list and not the row', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json({ options: [{ value: 'co-2', label: 'Neue Firma' }] })));
        const wrapper = companyIsland();
        await row(wrapper, 'company').get('button').trigger('click');
        const input = row(wrapper, 'company').get('[role="combobox"]');
        await input.setValue('Neu');
        await vi.waitFor(() => expect(input.attributes('aria-expanded')).toBe('true'));

        const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        input.element.dispatchEvent(escape);
        await flushPromises();

        expect(escape.defaultPrevented).toBe(true);
        expect(input.attributes('aria-expanded')).toBe('false');
        expect(row(wrapper, 'company').find('form').exists()).toBe(true);

        await input.trigger('keydown', { key: 'Escape' });

        expect(row(wrapper, 'company').find('form').exists()).toBe(false);
    });

    it('leaves the focus in another row when a slow answer arrives', async () => {
        let answer;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            answer = resolve;
        })));
        wrapper = island();

        await row(wrapper, 'phone').get('.property__edit').trigger('click');
        await row(wrapper, 'phone').get('input').setValue('+41 44 444 44 44');
        await row(wrapper, 'phone').get('form').trigger('submit');
        await row(wrapper, 'notes').get('.property__edit').trigger('click');
        await flushPromises();
        const notes = row(wrapper, 'notes').get('textarea').element;
        expect(document.activeElement).toBe(notes);

        answer(json({ value: '+41 44 444 44 44' }));
        await flushPromises();

        expect(row(wrapper, 'phone').find('form').exists()).toBe(false);
        expect(document.activeElement).toBe(notes);
    });
});
