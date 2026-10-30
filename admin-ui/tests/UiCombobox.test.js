import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UiCombobox from '../src/components/UiCombobox.vue';

const base = {
    id: 'f_town',
    name: 'f[town]',
    noResultsLabel: 'Keine Treffer.',
    sessionGoneLabel: 'Sitzung abgelaufen.',
    errorLabel: 'Fehler beim Laden.',
};

const towns = [
    { value: '1', label: '8706 Meilen', detail: 'ZH' },
    { value: '2', label: '8712 Stäfa', detail: 'ZH' },
];

const freeOptions = towns.map(({ label, detail }) => ({ value: label, label, detail }));

function json(body, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function box(props) {
    return mount(UiCombobox, { props: { ...base, ...props }, attachTo: document.body });
}

const visible = (combobox) => combobox.get('input[role="combobox"]');
const hidden = (combobox) => combobox.find('input[type="hidden"]');

async function type(combobox, text) {
    await visible(combobox).setValue(text);
    vi.advanceTimersByTime(150);
    await flushPromises();
}

function press(combobox, key) {
    const event = new KeyboardEvent('keydown', { key, cancelable: true, bubbles: true });
    visible(combobox).element.dispatchEvent(event);

    return event;
}

describe('UiCombobox', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    describe('select mode', () => {
        it('shows the label and submits the value through a hidden input', () => {
            const combobox = box({ value: '1', display: '8706 Meilen' });

            expect(visible(combobox).element.value).toBe('8706 Meilen');
            expect(visible(combobox).attributes('name')).toBeUndefined();
            expect(hidden(combobox).attributes('name')).toBe('f[town]');
            expect(hidden(combobox).element.value).toBe('1');
        });

        it('chooses with the keyboard', async () => {
            const combobox = box({ options: towns });

            await visible(combobox).setValue('8');
            press(combobox, 'ArrowDown');
            await flushPromises();
            expect(visible(combobox).attributes('aria-activedescendant')).toBe('f_town-option-0');

            const enter = press(combobox, 'Enter');
            await flushPromises();

            expect(enter.defaultPrevented).toBe(true);
            expect(hidden(combobox).element.value).toBe('1');
            expect(visible(combobox).element.value).toBe('8706 Meilen');
            expect(visible(combobox).attributes('aria-expanded')).toBe('false');
        });

        it('typing clears the value until an option is chosen', async () => {
            const combobox = box({ value: '1', display: '8706 Meilen', options: towns });

            await visible(combobox).setValue('8706 Me');

            const form = document.createElement('form');
            form.append(combobox.element);
            expect(new FormData(form).get('f[town]')).toBe('');
        });

        it('filters static options', async () => {
            const combobox = box({ options: towns });

            await visible(combobox).setValue('stä');

            expect(combobox.findAll('[role="option"]').map((option) => option.text())).toEqual(['8712 StäfaZH']);
        });

        it('opens static options on ArrowDown', async () => {
            const combobox = box({ options: towns });

            press(combobox, 'ArrowDown');
            await flushPromises();

            expect(visible(combobox).attributes('aria-expanded')).toBe('true');
        });

        it('fetches after the debounce and aborts a stale answer', async () => {
            const signals = [];
            vi.stubGlobal('fetch', vi.fn(async (url, init) => {
                signals.push(init.signal);

                return json({ options: url.includes('st') ? [towns[1]] : [towns[0]] });
            }));
            const combobox = box({ url: '/towns' });

            await visible(combobox).setValue('me');
            vi.advanceTimersByTime(150);
            await visible(combobox).setValue('st');
            vi.advanceTimersByTime(149);
            expect(fetch).toHaveBeenCalledTimes(1);
            vi.advanceTimersByTime(1);
            await flushPromises();

            expect(fetch).toHaveBeenCalledTimes(2);
            expect(fetch.mock.calls[1][0]).toBe('/towns?q=st');
            expect(signals[0].aborted).toBe(true);
            expect(combobox.findAll('[role="option"]')).toHaveLength(1);
            expect(combobox.get('[role="option"]').text()).toContain('Stäfa');
        });

        it('asks nothing below two characters', async () => {
            vi.stubGlobal('fetch', vi.fn());
            const combobox = box({ url: '/towns' });

            await type(combobox, 'a');

            expect(fetch).not.toHaveBeenCalled();
        });

        it('says when nothing matches', async () => {
            vi.stubGlobal('fetch', vi.fn(async () => json({ options: [] })));
            const combobox = box({ url: '/towns' });

            await type(combobox, 'xyz');

            expect(combobox.get('[role="status"]').text()).toBe('Keine Treffer.');
        });

        it('a 500 keeps the value and shows the error', async () => {
            vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>', { status: 500, headers: { 'Content-Type': 'text/html' } })));
            const combobox = box({ url: '/towns', value: '1', display: '8706 Meilen' });

            await type(combobox, '8706 Me');

            expect(combobox.get('[role="status"]').text()).toBe('Fehler beim Laden.');
            expect(visible(combobox).element.value).toBe('8706 Me');
        });

        it('a lost session says so', async () => {
            vi.stubGlobal('fetch', vi.fn(async () => json({ error: 'x' }, 401)));
            const combobox = box({ url: '/towns' });

            await type(combobox, 'meilen');

            expect(combobox.get('[role="status"]').text()).toBe('Sitzung abgelaufen.');
        });

        it('Esc and blur keep the state', async () => {
            const combobox = box({ value: '1', display: '8706 Meilen', options: towns });

            press(combobox, 'ArrowDown');
            await flushPromises();
            press(combobox, 'Escape');
            await flushPromises();
            await visible(combobox).trigger('blur');

            expect(visible(combobox).attributes('aria-expanded')).toBe('false');
            expect(hidden(combobox).element.value).toBe('1');
            expect(visible(combobox).element.value).toBe('8706 Meilen');
        });

        it('a pointer chooses without losing focus', async () => {
            const combobox = box({ options: towns });

            press(combobox, 'ArrowDown');
            await flushPromises();
            await combobox.findAll('[role="option"]')[1].trigger('mousedown');

            expect(hidden(combobox).element.value).toBe('2');
        });
    });

    describe('free-text mode', () => {
        const free = { freeText: true, value: '8706 Meilen', display: 'ignored' };

        it('shows the value and has the name on the visible input, with no hidden input', () => {
            const combobox = box(free);

            expect(visible(combobox).element.value).toBe('8706 Meilen');
            expect(visible(combobox).attributes('name')).toBe('f[town]');
            expect(hidden(combobox).exists()).toBe(false);
        });

        it('submits text that matches no option, as typed', async () => {
            const combobox = box({ ...free, options: freeOptions });

            await visible(combobox).setValue('9999 Nirgendwo');

            const form = document.createElement('form');
            form.append(combobox.element);
            expect(new FormData(form).get('f[town]')).toBe('9999 Nirgendwo');
        });

        it('choosing writes the option value', async () => {
            const combobox = box({ ...free, value: '', options: freeOptions });

            await visible(combobox).setValue('87');
            press(combobox, 'ArrowDown');
            await flushPromises();
            press(combobox, 'ArrowDown');
            await flushPromises();
            press(combobox, 'Enter');
            await flushPromises();

            expect(visible(combobox).element.value).toBe('8712 Stäfa');
        });

        it('Enter on an active option chooses and is prevented', async () => {
            const combobox = box({ ...free, value: '', options: freeOptions });

            await visible(combobox).setValue('87');
            press(combobox, 'ArrowDown');
            await flushPromises();

            expect(press(combobox, 'Enter').defaultPrevented).toBe(true);
        });

        it('Enter with no active option is left to the form', async () => {
            const combobox = box({ ...free, options: freeOptions });

            await visible(combobox).setValue('9999 Nirgendwo');

            expect(press(combobox, 'Enter').defaultPrevented).toBe(false);
            expect(visible(combobox).element.value).toBe('9999 Nirgendwo');
        });

        it('a late answer leaves the text alone', async () => {
            let answer;
            vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
                answer = resolve;
            })));
            const combobox = box({ ...free, url: '/towns' });

            await type(combobox, '8706');
            await visible(combobox).setValue('8706 Mei');
            await visible(combobox).trigger('blur');
            answer(json({ options: freeOptions }));
            await flushPromises();

            expect(visible(combobox).element.value).toBe('8706 Mei');
            expect(visible(combobox).attributes('aria-expanded')).toBe('false');
        });

        it('a 500 keeps the text', async () => {
            vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>', { status: 500, headers: { 'Content-Type': 'text/html' } })));
            const combobox = box({ ...free, url: '/towns' });

            await type(combobox, '8706 Mei');

            expect(visible(combobox).element.value).toBe('8706 Mei');
            expect(combobox.get('[role="status"]').text()).toBe('Fehler beim Laden.');
        });
    });
});
