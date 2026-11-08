import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test runs it as a
// script over a hand-built overlay form. Only "Filter löschen" is under test here (#51: radios clear like checkboxes).
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/overlay-form.js`, 'utf8');

function page() {
    document.body.innerHTML = `
        <div popover>
            <form data-overlay-form action="/eventlokale" method="get">
                <input type="hidden" name="canton" value="zh">
                <input type="radio" name="guests" value="50">
                <input type="radio" name="guests" value="150" checked>
                <input type="checkbox" name="setting[]" value="lake" checked>
                <input type="text" name="q" value="See">
            </form>
            <a href="/eventlokale?canton=zh" data-overlay-clear>Filter löschen</a>
        </div>`;
    new Function(SCRIPT)();

    return document.querySelector('form');
}

const clear = () => document.querySelector('[data-overlay-clear]').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

describe('overlay form clear (#12, #51)', () => {
    it('unchecks the radios and keeps their values', () => {
        const form = page();

        clear();

        const radios = [...form.querySelectorAll('input[type="radio"]')];
        expect(radios.map((radio) => radio.checked)).toEqual([false, false]);
        expect(radios.map((radio) => radio.value)).toEqual(['50', '150']);
    });

    it('unchecks a checkbox, empties a text field and keeps a hidden input', () => {
        const form = page();

        clear();

        expect(form.querySelector('input[type="checkbox"]').checked).toBe(false);
        expect(form.querySelector('input[type="checkbox"]').value).toBe('lake');
        expect(form.querySelector('input[type="text"]').value).toBe('');
        expect(form.querySelector('input[type="hidden"]').value).toBe('zh');
    });

    it('tells the form it changed and stays on the page', () => {
        const form = page();
        let changes = 0;
        form.addEventListener('change', () => changes++);

        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('[data-overlay-clear]').dispatchEvent(event);

        expect(changes).toBe(1);
        expect(event.defaultPrevented).toBe(true);
    });
});
