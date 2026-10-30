<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { adminFetch, SessionGone } from '../api.js';
import UiModal from '../components/UiModal.vue';
import { useListbox } from '../composables/useListbox.js';

// The ⌘K search. palette-trigger.js loads it on first use, so it opens as soon as it is mounted and takes
// over the click and the shortcut. A lost session shows a link rather than a redirect: another form on the page
// may hold unsaved work.
const DEBOUNCE_MS = 150;
const MIN_LENGTH = 2;

const props = defineProps({
    searchUrl: { type: String, required: true },
    loginUrl: { type: String, required: true },
    title: { type: String, required: true },
    inputLabel: { type: String, required: true },
    placeholder: { type: String, required: true },
    closeLabel: { type: String, required: true },
    hintLabel: { type: String, required: true },
    emptyLabel: { type: String, required: true },
    resultsOne: { type: String, required: true },
    resultsOther: { type: String, required: true }, // holds %n
    sessionGoneLabel: { type: String, required: true },
    loginLabel: { type: String, required: true },
    errorLabel: { type: String, required: true },
});

const open = ref(false);
const query = ref('');
const groups = ref([]);
const state = ref('hint'); // hint, results, session-gone, error
const input = ref(null);

let timer = null;
let controller = null;

const results = computed(() => groups.value.flatMap((group) => group.results));
const listbox = useListbox(results, {
    id: 'palette',
    choose: go,
    // Esc closes before the native close event, so UiModal emits nothing then: reset here, as a close does.
    dismiss: () => onClose(),
});

const firstIndex = computed(() => {
    let index = 0;

    return groups.value.map((group) => {
        const first = index;
        index += group.results.length;

        return first;
    });
});

const status = computed(() => {
    if (state.value === 'error') {
        return props.errorLabel;
    }
    if (state.value === 'hint') {
        return props.hintLabel;
    }

    const count = results.value.length;
    if (count === 0) {
        return props.emptyLabel;
    }

    return count === 1 ? props.resultsOne : props.resultsOther.replace('%n', String(count));
});

function go(result) {
    location.assign(result.url);
}

function clear() {
    groups.value = [];
    listbox.reset();
}

function cancelPending() {
    clearTimeout(timer);
    controller?.abort();
    controller = null;
}

async function search(q) {
    controller = new AbortController();
    const { signal } = controller;

    try {
        const answer = await adminFetch(`${props.searchUrl}?q=${encodeURIComponent(q)}`, { signal });
        if (!signal.aborted) {
            groups.value = answer.groups;
            state.value = 'results';
            listbox.activeIndex.value = results.value.length > 0 ? 0 : -1;
        }
    } catch (error) {
        if (error.name !== 'AbortError') {
            clear();
            state.value = error instanceof SessionGone ? 'session-gone' : 'error';
        }
    }
}

function onInput() {
    cancelPending();

    const q = query.value.trim();
    if (q.length < MIN_LENGTH) {
        clear();
        state.value = 'hint';

        return;
    }

    timer = setTimeout(() => search(q), DEBOUNCE_MS);
}

async function show() {
    open.value = true;
    await nextTick();
    input.value?.focus();
}

function onClose() {
    open.value = false;
    cancelPending();
    query.value = '';
    clear();
    state.value = 'hint';
}

function onDocumentClick(event) {
    if (event.target instanceof Element && event.target.closest('[data-palette-open]')) {
        show();
    }
}

function onDocumentKeydown(event) {
    if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey) {
        event.preventDefault();
        show();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onDocumentKeydown);
    show();
});

onBeforeUnmount(() => {
    cancelPending();
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onDocumentKeydown);
});
</script>

<template>
  <UiModal
    id="command-palette"
    :title="title"
    :close-label="closeLabel"
    :open="open"
    variant="palette"
    @close="onClose"
  >
    <label
      class="visually-hidden"
      for="palette-input"
    >{{ inputLabel }}</label>
    <input
      id="palette-input"
      ref="input"
      v-model="query"
      class="palette__input"
      type="search"
      role="combobox"
      autocomplete="off"
      autofocus
      aria-autocomplete="list"
      aria-controls="palette-results"
      :aria-expanded="results.length > 0 ? 'true' : 'false'"
      :aria-activedescendant="listbox.activeId.value"
      :placeholder="placeholder"
      @input="onInput"
      @keydown="listbox.onKeydown"
    >
    <p
      class="palette__status"
      role="status"
    >
      <template v-if="state === 'session-gone'">
        {{ sessionGoneLabel }}
        <a :href="loginUrl">{{ loginLabel }}</a>
      </template>
      <template v-else>
        {{ status }}
      </template>
    </p>
    <div
      id="palette-results"
      role="listbox"
      :aria-label="title"
    >
      <div
        v-for="(group, groupIndex) in groups"
        :key="group.label"
        class="palette__group"
        role="group"
        :aria-labelledby="`palette-group-${groupIndex}`"
      >
        <div
          :id="`palette-group-${groupIndex}`"
          class="palette__group-label"
        >
          {{ group.label }}
        </div>
        <div
          v-for="(result, resultIndex) in group.results"
          :id="listbox.optionId(firstIndex[groupIndex] + resultIndex)"
          :key="result.url"
          class="palette__option"
          role="option"
          :aria-selected="firstIndex[groupIndex] + resultIndex === listbox.activeIndex.value ? 'true' : 'false'"
          @mousedown.prevent="listbox.activeIndex.value = firstIndex[groupIndex] + resultIndex"
          @click="go(result)"
        >
          <span>{{ result.label }}</span>
          <span
            v-if="result.detail"
            class="palette__detail"
          >{{ result.detail }}</span>
        </div>
      </div>
    </div>
  </UiModal>
</template>
