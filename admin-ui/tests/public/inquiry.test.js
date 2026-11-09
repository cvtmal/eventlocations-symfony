import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test runs it as a script.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/inquiry.js`, 'utf8');

const TEMPLATE = 'Liebes Seeblick-Team,\n\nIst unser Wunschtermin noch frei?\n\nHerzliche Grüsse';

function mount(html) {
    document.body.innerHTML = html;
    new Function(SCRIPT)();
}

function message(value = TEMPLATE) {
    mount(`<button>vorher</button><textarea data-inquiry-message rows="7">${value}</textarea>`);

    return document.querySelector('textarea');
}

describe('inquiry.js, the prefilled message (F16 N4)', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('puts the caret at the end on a keyboard focus of the untouched template', () => {
        const field = message();

        field.focus();

        expect(field.selectionStart).toBe(TEMPLATE.length);
        expect(field.selectionEnd).toBe(TEMPLATE.length);
    });

    it('leaves the caret where a pointer put it', () => {
        const field = message();

        field.dispatchEvent(new Event('pointerdown'));
        field.focus();

        expect(field.selectionEnd).toBe(0);
    });

    it('acts on the first focus only, so a window refocus keeps a caret the guest put at 0', () => {
        const field = message();

        field.dispatchEvent(new Event('pointerdown'));
        field.focus();
        field.blur();
        field.focus();

        expect(field.selectionEnd).toBe(0);
    });

    it('still moves the caret after a swipe that started on the field focused nothing', () => {
        const field = message();

        field.dispatchEvent(new Event('pointerdown'));
        field.dispatchEvent(new Event('pointercancel'));
        field.focus();

        expect(field.selectionEnd).toBe(TEMPLATE.length);
    });

    it('leaves an edited message alone', () => {
        const field = message();

        field.value = `Wir sind 40 Leute. ${TEMPLATE}`;
        field.setSelectionRange(0, 0);
        field.focus();

        expect(field.selectionEnd).toBe(0);
    });

    it('leaves a caret the guest placed inside the template alone', () => {
        const field = message();

        field.setSelectionRange(5, 5);
        field.focus();

        expect(field.selectionEnd).toBe(5);
    });
});

describe('inquiry.js, the outcome (F16 N5)', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('focuses the confirmation on load', () => {
        mount('<a href="#">vorher</a><h2 tabindex="-1" data-inquiry-focus>Danke! Deine Anfrage ist unterwegs.</h2>');

        expect(document.activeElement).toBe(document.querySelector('h2'));
    });

    it('leaves the focus alone on a page without an outcome', () => {
        mount('<textarea data-inquiry-message></textarea>');

        expect(document.activeElement).toBe(document.body);
    });
});
