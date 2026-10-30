import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useListbox } from '../src/composables/useListbox.js';

function setup(values = ['a', 'b', 'c']) {
    const items = ref(values);
    const choose = vi.fn();
    const dismiss = vi.fn();

    return { items, choose, dismiss, listbox: useListbox(items, { id: 'town', choose, dismiss }) };
}

function press(listbox, key) {
    const event = new KeyboardEvent('keydown', { key, cancelable: true });
    listbox.onKeydown(event);

    return event;
}

describe('useListbox', () => {
    it('starts with no active option', () => {
        const { listbox } = setup();

        expect(listbox.activeIndex.value).toBe(-1);
        expect(listbox.activeId.value).toBeUndefined();
    });

    it('moves down and wraps', () => {
        const { listbox } = setup();

        press(listbox, 'ArrowDown');
        expect(listbox.activeIndex.value).toBe(0);
        expect(listbox.activeId.value).toBe('town-option-0');

        press(listbox, 'ArrowDown');
        press(listbox, 'ArrowDown');
        press(listbox, 'ArrowDown');
        expect(listbox.activeIndex.value).toBe(0);
    });

    it('moves up and wraps, starting from the last option', () => {
        const { listbox } = setup();

        press(listbox, 'ArrowUp');
        expect(listbox.activeIndex.value).toBe(2);

        press(listbox, 'ArrowUp');
        expect(listbox.activeIndex.value).toBe(1);
    });

    it('prevents the caret from moving on arrows', () => {
        const { listbox } = setup();

        expect(press(listbox, 'ArrowDown').defaultPrevented).toBe(true);
    });

    it('does nothing on an empty list', () => {
        const { listbox } = setup([]);

        press(listbox, 'ArrowDown');

        expect(listbox.activeIndex.value).toBe(-1);
    });

    it('Home and End jump only with an active option, or they move the caret', () => {
        const { listbox } = setup();

        expect(press(listbox, 'End').defaultPrevented).toBe(false);
        expect(listbox.activeIndex.value).toBe(-1);

        press(listbox, 'ArrowDown');
        expect(press(listbox, 'End').defaultPrevented).toBe(true);
        expect(listbox.activeIndex.value).toBe(2);
        press(listbox, 'Home');
        expect(listbox.activeIndex.value).toBe(0);
    });

    it('Enter chooses the active option and is prevented', () => {
        const { listbox, choose } = setup();

        press(listbox, 'ArrowDown');
        press(listbox, 'ArrowDown');

        expect(press(listbox, 'Enter').defaultPrevented).toBe(true);
        expect(choose).toHaveBeenCalledWith('b');
    });

    it('Enter without an active option is left to the form', () => {
        const { listbox, choose } = setup();

        expect(press(listbox, 'Enter').defaultPrevented).toBe(false);
        expect(choose).not.toHaveBeenCalled();
    });

    it('Escape dismisses', () => {
        const { listbox, dismiss } = setup();

        press(listbox, 'Escape');

        expect(dismiss).toHaveBeenCalledOnce();
    });

    it('reset clears the active option', () => {
        const { listbox } = setup();

        press(listbox, 'ArrowDown');
        listbox.reset();

        expect(listbox.activeIndex.value).toBe(-1);
    });
});
