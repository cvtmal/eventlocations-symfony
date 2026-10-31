import { afterEach, beforeAll, describe, expect, it } from 'vitest';

// assets/js/admin/serp-preview.js listens on document, as the other delegated scripts of vanilla-scripts.test.js.
beforeAll(async () => {
    await import('../../assets/js/admin/serp-preview.js');
});

afterEach(() => {
    document.body.replaceChildren();
});

function editor() {
    document.body.innerHTML = `
        <input id="t" data-serp-source="title" data-serp-locale="de" placeholder="Standardtitel">
        <textarea id="d" data-serp-source="description" data-serp-locale="de" placeholder="Standardbeschreibung"></textarea>
        <p data-serp-target="title" data-serp-locale="de">Standardtitel</p>
        <p data-serp-target="description" data-serp-locale="de">Standardbeschreibung</p>
        <p data-serp-target="title" data-serp-locale="fr">Titre</p>`;
}

function type(id, value) {
    const field = document.getElementById(id);
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

function shown(name, locale = 'de') {
    return document.querySelector(`[data-serp-target="${name}"][data-serp-locale="${locale}"]`).textContent;
}

describe('serp-preview.js', () => {
    it('shows a typed title and description, trimmed', () => {
        editor();

        type('t', '  Heiraten am Zürichsee ');
        type('d', 'Schlösser und Seeufer.');

        expect(shown('title')).toBe('Heiraten am Zürichsee');
        expect(shown('description')).toBe('Schlösser und Seeufer.');
    });

    it('shows the default again when a field is emptied', () => {
        editor();
        type('t', 'Eigener Titel');

        type('t', '   ');

        expect(shown('title')).toBe('Standardtitel');
    });

    it('updates only the preview of the field\'s locale', () => {
        editor();

        type('t', 'Eigener Titel');

        expect(shown('title', 'fr')).toBe('Titre');
    });

    it('ignores fields without the hook', () => {
        editor();
        document.body.insertAdjacentHTML('beforeend', '<input id="other" data-serp-locale="de">');

        type('other', 'Etwas');

        expect(shown('title')).toBe('Standardtitel');
    });
});
