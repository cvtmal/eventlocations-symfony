import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import UiButton from '../src/components/UiButton.vue';
import UiModal from '../src/components/UiModal.vue';
import UiPill from '../src/components/UiPill.vue';
import UiTableRow from '../src/components/UiTableRow.vue';

// Twig and Vue render the same markup and classes (S12). Each side compares itself with a committed fixture,
// so the order of PHPUnit and Vitest does not matter. tests/Integration/Admin/ComponentMarkupFixturesTest.php is
// the Twig side and canonicalises the same way: no comments, no whitespace-only text, trimmed text, sorted
// attributes, the sprite URL reduced to #name.
const VOID = new Set(['input']);

const escapeText = (text) => text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const escapeAttribute = (text) => escapeText(text).replaceAll('"', '&quot;');

function canonical(element) {
    const tag = element.localName;
    const attributes = [...element.attributes]
        .map(({ name, value }) => [name, tag === 'use' && name === 'href' ? value.slice(value.indexOf('#')) : value])
        .sort(([a], [b]) => (a < b ? -1 : 1))
        .map(([name, value]) => ` ${name}="${escapeAttribute(value)}"`)
        .join('');

    if (VOID.has(tag)) {
        return `<${tag}${attributes}>`;
    }

    const children = [...element.childNodes].map((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) {
            return canonical(node);
        }

        return node.nodeType === Node.TEXT_NODE ? escapeText(node.textContent.trim()) : '';
    }).join('');

    return `<${tag}${attributes}>${children}</${tag}>`;
}

function fixture(name) {
    return readFileSync(`${import.meta.dirname}/fixtures/markup/${name}.html`, 'utf8').trim();
}

describe('Vue renders the fixtures', () => {
    it('button', () => {
        const button = mount(UiButton, { props: { label: 'Speichern', variant: 'primary', icon: 'plus' } });

        expect(canonical(button.element)).toBe(fixture('button'));
    });

    it('pill', () => {
        const pill = mount(UiPill, { props: { label: 'Publiziert', tone: 'green', dot: true } });

        expect(canonical(pill.element)).toBe(fixture('pill'));
    });

    it('table row', () => {
        const row = mount(UiTableRow, {
            props: { selectName: 'venue[]', selectValue: '1042', selectLabel: 'Zeile 1042 auswählen' },
            slots: {
                default: () => h('td', 'Schloss Seeblick'),
                actions: () => h(UiButton, { label: 'Aktionen', variant: 'ghost', icon: 'ellipsis', iconOnly: true }),
            },
        });

        expect(canonical(row.element)).toBe(fixture('table-row'));
    });

    it('table row without actions', () => {
        const row = mount(UiTableRow, {
            props: { actionsColumn: true },
            slots: { default: () => h('td', 'Schloss Seeblick') },
        });

        expect(canonical(row.element)).toBe(fixture('table-row-no-actions'));
    });

    it('modal', () => {
        const modal = mount(UiModal, {
            props: { id: 'demo', title: 'Änderungen verwerfen?', closeLabel: 'Schliessen' },
            slots: {
                default: () => h('p', 'Nicht gespeicherte Eingaben gehen verloren.'),
                footer: () => h(UiButton, { label: 'Abbrechen' }),
            },
        });

        expect(canonical(modal.element)).toBe(fixture('modal'));
    });
});
