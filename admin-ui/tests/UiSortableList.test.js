import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import UiSortableList from '../src/components/UiSortableList.vue';

const props = {
    items: [{ id: 'a', label: 'Eins' }, { id: 'b', label: 'Zwei' }, { id: 'c', label: 'Drei' }],
    label: 'Fotos',
    upLabel: 'Nach oben',
    downLabel: 'Nach unten',
    movedLabel: '%label ist jetzt an Position %position.',
};

const labels = (list) => list.findAll('.sortable__label').map((label) => label.text());

function mounted() {
    return mount(UiSortableList, { props, attachTo: document.body });
}

describe('UiSortableList', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('moves an item up and reports the new order', async () => {
        const list = mounted();

        await list.get('[data-move="up-b"]').trigger('click');

        expect(labels(list)).toEqual(['Zwei', 'Eins', 'Drei']);
        expect(list.emitted('reorder')[0][0]).toEqual(['b', 'a', 'c']);
    });

    it('moves an item down', async () => {
        const list = mounted();

        await list.get('[data-move="down-a"]').trigger('click');

        expect(labels(list)).toEqual(['Zwei', 'Eins', 'Drei']);
    });

    it('does nothing at the boundaries, whose buttons stay focusable', async () => {
        const list = mounted();
        const first = list.get('[data-move="up-a"]');
        const last = list.get('[data-move="down-c"]');

        expect(first.attributes('aria-disabled')).toBe('true');
        expect(first.attributes('disabled')).toBeUndefined();
        expect(last.attributes('aria-disabled')).toBe('true');

        await first.trigger('click');
        await last.trigger('click');

        expect(labels(list)).toEqual(['Eins', 'Zwei', 'Drei']);
        expect(list.emitted('reorder')).toBeUndefined();
    });

    it('keeps focus on the moved item', async () => {
        const list = mounted();
        const button = list.get('[data-move="down-a"]');

        button.element.focus();
        await button.trigger('click');
        await nextTick();

        expect(document.activeElement.dataset.move).toBe('down-a');
    });

    it('announces the new position', async () => {
        const list = mounted();

        await list.get('[data-move="down-a"]').trigger('click');

        expect(list.get('[role="status"]').text()).toBe('Eins ist jetzt an Position 2.');
    });

    it('reorders by drag and drop', async () => {
        const list = mounted();
        const [first, , third] = list.findAll('li');

        await first.trigger('dragstart');
        await third.trigger('drop');

        expect(labels(list)).toEqual(['Zwei', 'Drei', 'Eins']);
        expect(list.emitted('reorder')[0][0]).toEqual(['b', 'c', 'a']);
    });

    it('follows new items from its parent', async () => {
        const list = mounted();

        await list.setProps({ items: [{ id: 'z', label: 'Neu' }] });

        expect(labels(list)).toEqual(['Neu']);
    });
});
