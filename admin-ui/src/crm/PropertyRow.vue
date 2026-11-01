<script setup>
import { computed, nextTick, ref } from 'vue';
import { adminFetch } from '../api.js';
import UiButton from '../components/UiButton.vue';
import UiCombobox from '../components/UiCombobox.vue';
import { failureOf } from './failure.js';

// One inline-edited field (D10): the value, an edit button, and while editing a small form with Speichern
// and Abbrechen. One PATCH per save; `expected` is the value of the last answer, the field's version (D11).
// The form is read through FormData, so a combobox in select mode sends its hidden id. `readonly` shows the value with
// no edit button. `draft` is the text to edit where the stored value is another thing: an amount is stored in Rappen,
// and the API reads what is sent as francs, so editing `value` would multiply it by a hundred on an idle save.
const props = defineProps({
    url: { type: String, required: true },
    field: { type: Object, required: true }, // {name, label, type, value, display, draft, readonly, options, lookupUrl, required}
    labels: { type: Object, required: true },
    idPrefix: { type: String, default: 'property' },
});

const emit = defineEmits(['saved']);

const value = ref(props.field.value ?? null);
const display = ref('');
const editing = ref(false);
const saving = ref(false);
const failure = ref(null);
const draft = ref('');
const editText = ref(props.field.draft ?? null);
const form = ref(null);
const editButton = ref(null);

const inputId = `${props.idPrefix}-${props.field.name}`;
const labelId = `${inputId}-label`;
const errorId = `${inputId}-error`;
const reloadHref = window.location.href.split('#')[0];

const options = computed(() => props.field.options ?? []);
const describedBy = computed(() => (failure.value ? errorId : undefined));
const invalid = computed(() => (failure.value ? 'true' : undefined));
// The browser turns a Unicode domain into punycode in `type="email"`, as the create form's plain input does not.
const inputType = computed(() => (['email', 'amount'].includes(props.field.type) ? 'text' : props.field.type));
const inputMode = computed(() => ({ email: 'email', amount: 'decimal' })[props.field.type]);

function optionLabel(code) {
    return options.value.find((option) => option.value === code)?.label ?? code;
}

function displayOf(stored, typed) {
    if (stored === null || stored === '') {
        return '';
    }
    if (props.field.type === 'select') {
        return optionLabel(stored);
    }
    if (props.field.type === 'combobox') {
        return typed || optionLabel(stored);
    }

    return stored;
}

display.value = props.field.display ?? displayOf(value.value, '');

function focusFirstControl() {
    form.value?.querySelector('input:not([type="hidden"]), textarea, select')?.focus();
}

async function edit() {
    draft.value = editText.value ?? value.value ?? '';
    failure.value = null;
    editing.value = true;
    await nextTick();
    focusFirstControl();
}

async function close() {
    // A slow answer leaves the focus alone once it is in another row. `body`: Safari does not focus a clicked button.
    const focusHere = form.value?.contains(document.activeElement) || document.activeElement === document.body;
    editing.value = false;
    failure.value = null;
    await nextTick();
    if (focusHere) {
        editButton.value?.focus();
    }
}

// The write cannot be recalled, so a save in flight cannot be cancelled: its answer closes the row.
function cancel() {
    if (!saving.value) {
        close();
    }
}

// Esc cancels, unless an open combobox list took it (it prevents the default).
function onKeydown(event) {
    if (event.key !== 'Escape' || event.defaultPrevented) {
        return;
    }

    event.preventDefault();
    cancel();
}

async function save() {
    if (saving.value) {
        return;
    }

    // A half-typed date reads as empty, and `novalidate` lets it through, so it would erase the stored date.
    if (form.value.elements.namedItem(props.field.name)?.validity?.badInput) {
        failure.value = { message: props.labels.badDate, reload: false };

        return;
    }

    const sent = new FormData(form.value).get(props.field.name);
    const typed = form.value.querySelector('[role="combobox"]')?.value ?? '';
    // Text with no option chosen is a search, not a value: only an emptied combobox clears the field.
    if (sent === '' && typed.trim() !== '') {
        failure.value = { message: props.labels.choose, reload: false };

        return;
    }

    saving.value = true;
    failure.value = null;

    try {
        const answer = await adminFetch(props.url, {
            method: 'PATCH',
            body: { field: props.field.name, value: typeof sent === 'string' ? sent : null, expected: value.value },
        });
        const changed = answer.value !== value.value;
        value.value = answer.value;
        display.value = answer.display ?? displayOf(answer.value, typed);
        editText.value = answer.draft ?? null;
        if (changed) {
            emit('saved', { name: props.field.name, value: value.value, display: display.value });
        }
        // What was typed during the save stays: the row closes only on what it sent.
        if (new FormData(form.value).get(props.field.name) === sent) {
            await close();
        }
    } catch (error) {
        failure.value = failureOf(error, props.labels);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
  <div
    class="property"
    :class="{ 'property--editing': editing }"
    :data-property="field.name"
  >
    <template v-if="!editing">
      <div class="property__head">
        <span
          :id="labelId"
          class="property__label"
        >{{ field.label }}</span>
        <button
          v-if="!field.readonly"
          ref="editButton"
          type="button"
          class="property__edit"
          :aria-describedby="labelId"
          @click="edit"
        >
          {{ labels.edit }}
        </button>
      </div>
      <span
        class="property__value"
        :class="{ 'property__value--empty': display === '' }"
      >{{ display === '' ? labels.empty : display }}</span>
    </template>
    <form
      v-else
      ref="form"
      class="property__form"
      novalidate
      @submit.prevent="save"
      @keydown="onKeydown"
    >
      <label
        class="property__label"
        :for="inputId"
      >{{ field.label }}</label>
      <textarea
        v-if="field.type === 'textarea'"
        :id="inputId"
        v-model="draft"
        class="field__input"
        :name="field.name"
        rows="4"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      />
      <select
        v-else-if="field.type === 'select'"
        :id="inputId"
        v-model="draft"
        class="field__input"
        :name="field.name"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      >
        <option
          v-if="!field.required"
          value=""
        >
          {{ labels.none }}
        </option>
        <option
          v-for="option in options"
          :key="option.value"
          :value="option.value"
        >
          {{ option.label }}
        </option>
      </select>
      <UiCombobox
        v-else-if="field.type === 'combobox'"
        :id="inputId"
        :name="field.name"
        :value="value ?? ''"
        :display="display"
        :url="field.lookupUrl"
        :options="options"
        :list-label="field.label"
        :described-by="describedBy"
        :invalid="failure !== null"
        :required="field.required ?? false"
        :no-results-label="labels.noResults"
        :session-gone-label="labels.sessionGone"
        :error-label="labels.error"
      />
      <input
        v-else
        :id="inputId"
        v-model="draft"
        class="field__input"
        :type="inputType"
        :inputmode="inputMode"
        :name="field.name"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      >
      <p
        v-if="failure"
        :id="errorId"
        class="field__error"
        role="alert"
      >
        {{ failure.message }}
        <a
          v-if="failure.reload"
          :href="reloadHref"
        >{{ labels.reload }}</a>
      </p>
      <div class="property__actions">
        <UiButton
          :label="labels.save"
          variant="primary"
          size="sm"
          type="submit"
          :aria-busy="saving ? 'true' : undefined"
        />
        <UiButton
          :label="labels.cancel"
          variant="ghost"
          size="sm"
          :aria-disabled="saving ? 'true' : undefined"
          @click="cancel"
        />
      </div>
    </form>
  </div>
</template>
