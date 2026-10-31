<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { adminFetch, SessionGone } from '../api.js';
import { useListbox } from '../composables/useListbox.js';

// Two stated modes. Select mode (default): the value differs from the label, such as an id. A hidden input
// carries it, typing clears it until an option is chosen. Free-text mode: value = label, such as #24's
// "8706 Meilen". The visible input carries it, and what was typed is submitted, option or not.
const DEBOUNCE_MS = 150;
const MIN_LENGTH = 2;

const props = defineProps({
    id: { type: String, required: true },
    name: { type: String, required: true },
    value: { type: String, default: '' },
    display: { type: String, default: '' },
    freeText: { type: Boolean, default: false },
    url: { type: String, default: undefined }, // answers {"options": [{"value", "label", "detail"}]}
    options: { type: Array, default: () => [] },
    listLabel: { type: String, default: undefined },
    describedBy: { type: String, default: undefined },
    invalid: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false }, // a dialog's first focus (dialog focusing steps)
    placeholder: { type: String, default: undefined },
    noResultsLabel: { type: String, required: true },
    sessionGoneLabel: { type: String, required: true },
    errorLabel: { type: String, required: true },
});

const text = ref(props.freeText ? props.value : props.display);
const selected = ref(props.value);
const results = ref([]);
const open = ref(false);
const message = ref('');
const listId = `${props.id}-list`;

let timer = null;
let controller = null;

const listbox = useListbox(results, {
    id: props.id,
    choose,
    dismiss: close,
});

const expanded = computed(() => open.value && results.value.length > 0);

function close() {
    open.value = false;
    listbox.reset();
}

function choose(option) {
    text.value = props.freeText ? option.value : option.label;
    selected.value = option.value;
    message.value = '';
    close();
}

function show(options) {
    results.value = options;
    open.value = true;
    message.value = options.length === 0 ? props.noResultsLabel : '';
    listbox.reset();
}

function matches(option, needle) {
    return option.label.toLowerCase().includes(needle);
}

function cancelPending() {
    clearTimeout(timer);
    controller?.abort();
    controller = null;
}

async function load(q) {
    controller = new AbortController();
    const { signal } = controller;

    try {
        const { options } = await adminFetch(`${props.url}?q=${encodeURIComponent(q)}`, { signal });
        if (!signal.aborted) {
            show(options);
        }
    } catch (error) {
        if (error.name !== 'AbortError') {
            close();
            message.value = error instanceof SessionGone ? props.sessionGoneLabel : props.errorLabel;
        }
    }
}

function onInput() {
    cancelPending();
    if (!props.freeText) {
        selected.value = '';
    }

    const q = text.value.trim();
    if (!props.url) {
        show(q === '' ? props.options : props.options.filter((option) => matches(option, q.toLowerCase())));

        return;
    }
    if (q.length < MIN_LENGTH) {
        message.value = '';
        close();

        return;
    }

    timer = setTimeout(() => load(q), DEBOUNCE_MS);
}

function onKeydown(event) {
    if (event.key === 'ArrowDown' && !expanded.value && !props.url) {
        event.preventDefault();
        show(props.options);

        return;
    }
    // Esc closes the list and nothing else: not a row around it, not its dialog.
    if (event.key === 'Escape' && expanded.value) {
        event.preventDefault();
    }

    listbox.onKeydown(event);
}

// A late answer finds nothing to open: the signal is aborted.
function onBlur() {
    cancelPending();
    close();
}

onBeforeUnmount(cancelPending);
</script>

<template>
  <div class="combobox">
    <input
      v-if="!freeText"
      type="hidden"
      :name="name"
      :value="selected"
    >
    <input
      :id="id"
      v-model="text"
      class="field__input"
      type="text"
      role="combobox"
      autocomplete="off"
      aria-autocomplete="list"
      :name="freeText ? name : undefined"
      :aria-expanded="expanded ? 'true' : 'false'"
      :aria-controls="listId"
      :aria-activedescendant="listbox.activeId.value"
      :aria-describedby="describedBy"
      :aria-invalid="invalid ? 'true' : undefined"
      :required="required"
      :autofocus="autofocus"
      :placeholder="placeholder"
      @input="onInput"
      @keydown="onKeydown"
      @blur="onBlur"
    >
    <ul
      :id="listId"
      class="combobox__list"
      role="listbox"
      :aria-label="listLabel"
      :hidden="!expanded"
    >
      <li
        v-for="(option, index) in results"
        :id="listbox.optionId(index)"
        :key="option.value"
        class="combobox__option"
        role="option"
        :aria-selected="index === listbox.activeIndex.value ? 'true' : 'false'"
        @mousedown.prevent="choose(option)"
      >
        <span>{{ option.label }}</span>
        <span
          v-if="option.detail"
          class="combobox__detail"
        >{{ option.detail }}</span>
      </li>
    </ul>
    <p
      class="combobox__status"
      role="status"
    >
      {{ message }}
    </p>
  </div>
</template>
