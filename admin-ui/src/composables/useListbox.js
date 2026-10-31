import { computed, ref, watch } from 'vue';

// Keyboard state of a listbox whose focus stays in an input (aria-activedescendant). The combobox and the
// command palette share it. Enter with no active option and Home or End with none do nothing, so the input
// keeps its own behaviour: submitting the form and moving the caret.
export function useListbox(items, { id, choose, dismiss }) {
    const activeIndex = ref(-1);

    const optionId = (index) => `${id}-option-${index}`;
    const activeId = computed(() => (activeIndex.value < 0 ? undefined : optionId(activeIndex.value)));

    // The active option stays visible, in a list that scrolls and in a dialog body that does.
    watch(activeIndex, (index) => {
        if (index >= 0) {
            document.getElementById(optionId(index))?.scrollIntoView?.({ block: 'nearest' });
        }
    }, { flush: 'post' });

    function reset() {
        activeIndex.value = -1;
    }

    function move(delta) {
        const count = items.value.length;
        if (count > 0) {
            activeIndex.value = activeIndex.value < 0 && delta < 0 ? count - 1 : (activeIndex.value + delta + count) % count;
        }
    }

    function onKeydown(event) {
        const count = items.value.length;
        const active = activeIndex.value >= 0 && activeIndex.value < count;

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                move(-1);
                break;
            case 'Home':
                if (active) {
                    event.preventDefault();
                    activeIndex.value = 0;
                }
                break;
            case 'End':
                if (active) {
                    event.preventDefault();
                    activeIndex.value = count - 1;
                }
                break;
            case 'Enter':
                if (active) {
                    event.preventDefault();
                    choose(items.value[activeIndex.value]);
                }
                break;
            case 'Escape':
                dismiss();
                break;
        }
    }

    return { activeIndex, activeId, optionId, onKeydown, reset };
}
