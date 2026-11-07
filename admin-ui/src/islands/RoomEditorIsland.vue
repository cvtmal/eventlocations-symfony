<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { ValidationFailed } from '../api.js';
import CmsField from '../cms/CmsField.vue';
import { charCount, failureOf, fill } from '../cms/text.js';
import { createWrites, freshToken } from '../cms/writes.js';
import UiButton from '../components/UiButton.vue';
import UiDrawer from '../components/UiDrawer.vue';
import UiEmpty from '../components/UiEmpty.vue';
import UiModal from '../components/UiModal.vue';
import UiPill from '../components/UiPill.vue';
import UiSortableList from '../components/UiSortableList.vue';
import { useToast } from '../composables/useToast.js';

// The Räume tab (#25, CMS-5). Props: templates/admin/cms/venue/rooms.html.twig. The drawer posts its whole state with
// exactly RoomType's keys, because the room body is strict. Error fields are the API's dotted paths (`names.de`,
// `seating.banquet.max`); any other field lands in the drawer's alert, so no message is ever dropped.
const props = defineProps({
    rooms: { type: Array, required: true },
    locales: { type: Array, required: true },
    defaultLocale: { type: String, required: true },
    floorMaterials: { type: Array, required: true },
    seatingTypes: { type: Array, required: true },
    nameMax: { type: Number, required: true },
    descriptionMax: { type: Number, required: true },
    idPlaceholder: { type: String, required: true },
    endpoints: { type: Object, required: true },
    reloadUrl: { type: String, required: true },
    labels: { type: Object, required: true },
});

// Well above a save held by the session lock of an upload in another tab, seconds, and a 504 from the server.
const SAVE_TIMEOUT_MS = 90000;

const toast = useToast({ dismissLabel: props.labels.close });

const rooms = ref(props.rooms);
const editing = ref(null); // null, or {room: ?object}: the drawer's open state
const form = ref(null);
const fieldErrors = ref({});
const alert = ref(null);
const saving = ref(false);
const removing = ref(null);
const formElement = ref(null);
const addButton = ref(null);

const writes = createWrites('rooms', (list) => {
    if (list) {
        alert.value = null; // Whatever it warned of, a write went through since.
    }
    rooms.value = list ?? [...rooms.value];
});

const url = (template, id) => template.replace(props.idPlaceholder, id);
const perLocale = (value) => Object.fromEntries(props.locales.map(({ code }) => [code, value(code)]));
const perSeating = (value) => Object.fromEntries(props.seatingTypes.map(({ value: type }) => [type, value(type)]));

function nameOf(room) {
    return room.names[props.defaultLocale] ?? Object.values(room.names)[0] ?? '';
}

function summaryOf(room) {
    const { labels } = props;
    const { capacityMin: min, capacityMax: max, areaM2: area, photoCount: count } = room;

    return [
        max !== null ? fill(min !== null ? labels.capacityRange : labels.capacityMax, { min, max }) : null,
        area !== null ? fill(labels.area, { area }) : null,
        count > 0 ? fill(count === 1 ? labels.photoCountOne : labels.photoCountOther, { count }) : null,
    ].filter(Boolean).join(' · ');
}

const items = computed(() => [...rooms.value]
    .sort((a, b) => a.position - b.position)
    .map((room) => ({ id: room.id, label: nameOf(room), summary: summaryOf(room), room })));

const fieldNames = computed(() => [
    ...props.locales.flatMap(({ code }) => [`names.${code}`, `descriptions.${code}`]),
    'capacityMin',
    'capacityMax',
    'areaM2',
    'heightM',
    'floorMaterial',
    ...props.seatingTypes.flatMap(({ value }) => [`seating.${value}.min`, `seating.${value}.max`]),
]);

function formOf(room) {
    return {
        names: perLocale((code) => room?.names[code] ?? ''),
        descriptions: perLocale((code) => room?.descriptions[code] ?? ''),
        capacityMin: room?.capacityMin ?? '',
        capacityMax: room?.capacityMax ?? '',
        areaM2: room?.areaM2 ?? '',
        heightM: room?.heightM ?? '',
        floorMaterial: room?.floorMaterial ?? '',
        seating: perSeating((type) => ({ min: room?.seating[type]?.min ?? '', max: room?.seating[type]?.max ?? '' })),
    };
}

const orNull = (value) => (value === '' ? null : value);

// A room's guest range follows its layouts (F4, Seating::guestRange()): once a layout states a maximum,
// the room takes the greatest maximum and the least minimum of those layouts, and its own fields lock.
const layoutRange = computed(() => {
    if (!form.value) {
        return null;
    }
    let min = null;
    let max = null;
    for (const range of Object.values(form.value.seating)) {
        if (range.max === '' || range.max === null) {
            continue;
        }
        max = max === null ? Number(range.max) : Math.max(max, Number(range.max));
        if (range.min !== '' && range.min !== null) {
            min = min === null ? Number(range.min) : Math.min(min, Number(range.min));
        }
    }

    return max === null ? null : { min, max };
});

watch(layoutRange, (range) => {
    if (range) {
        form.value.capacityMin = range.min ?? '';
        form.value.capacityMax = range.max;
    }
});

function bodyOf(values) {
    return {
        names: { ...values.names },
        descriptions: { ...values.descriptions },
        capacityMin: orNull(values.capacityMin),
        capacityMax: orNull(values.capacityMax),
        areaM2: orNull(values.areaM2),
        heightM: orNull(values.heightM),
        floorMaterial: orNull(values.floorMaterial),
        seating: perSeating((type) => ({ min: orNull(values.seating[type].min), max: orNull(values.seating[type].max) })),
    };
}

function open(room) {
    form.value = formOf(room);
    fieldErrors.value = {};
    alert.value = null;
    editing.value = { room };
}

function close() {
    editing.value = null;
}

const generalErrors = computed(() => Object.entries(fieldErrors.value)
    .filter(([field]) => !fieldNames.value.includes(field))
    .flatMap(([, messages]) => messages));

function failed(error) {
    if (error instanceof ValidationFailed) {
        fieldErrors.value = error.errors;
        alert.value = null;
        nextTick(() => formElement.value?.querySelector('[aria-invalid="true"]')?.focus());

        return;
    }

    fieldErrors.value = {};
    alert.value = failureOf(error, props.labels);
}

// From Speichern to the answer the drawer is open and inert, so an answer only ever meets its own room. Nothing else
// can end a hung request, and the form has to come back.
async function save() {
    if (saving.value) {
        return;
    }

    const { room } = editing.value;
    const timeout = new AbortController();
    const timer = setTimeout(() => timeout.abort(), SAVE_TIMEOUT_MS);

    saving.value = true;
    fieldErrors.value = {};
    alert.value = null;

    const body = bodyOf(form.value);
    const { signal } = timeout;

    try {
        if (room) {
            // `room` is a snapshot from open(); a reorder since then moved the row's token on.
            await writes.send(url(props.endpoints.change, room.id), (held) => ({
                method: 'PUT',
                body: { loadedAt: freshToken(held ?? rooms.value, room), ...body },
                signal,
            }));
        } else {
            await writes.send(props.endpoints.add, { method: 'POST', body, signal });
        }

        close();
        toast.show(room ? props.labels.saved : props.labels.added);
    } catch (error) {
        failed(error);
    } finally {
        clearTimeout(timer);
        saving.value = false;
    }
}

async function reorder(ids) {
    try {
        await writes.send(props.endpoints.order, { method: 'PUT', body: { ids } });
    } catch (error) {
        const failure = failureOf(error, props.labels);

        if (failure.reload) {
            alert.value = failure;
        } else {
            toast.show(props.labels.orderFailed, 'error');
        }
    }
}

async function remove() {
    const room = removing.value;

    removing.value = null;
    alert.value = null;

    try {
        await writes.send(url(props.endpoints.remove, room.id), { method: 'DELETE' });
        toast.show(props.labels.removed);
        await nextTick();
        addButton.value?.$el.focus(); // The button that opened the modal has left the document.
    } catch (error) {
        failed(error);
    }
}
</script>

<template>
  <div class="room-editor">
    <div class="room-editor__head">
      <h2 class="room-editor__title">
        {{ labels.title }}
      </h2>
      <UiButton
        ref="addButton"
        :label="labels.add"
        variant="primary"
        icon="plus"
        data-room-add
        @click="open(null)"
      />
    </div>

    <p
      v-if="alert && !editing"
      class="media-alert"
      role="alert"
    >
      {{ alert.message }}
      <a
        v-if="alert.reload"
        :href="reloadUrl"
      >{{ labels.reload }}</a>
    </p>

    <UiSortableList
      v-if="items.length"
      class="room-list"
      :items="items"
      :label="labels.listLabel"
      :up-label="labels.moveUp"
      :down-label="labels.moveDown"
      :moved-label="labels.moved"
      @reorder="reorder"
    >
      <template #item="{ item }">
        <span class="sortable__label room-list__main">
          <span class="room-list__name">{{ item.label }}</span>
          <span
            v-if="item.summary"
            class="room-list__summary"
          >{{ item.summary }}</span>
          <UiPill
            v-if="item.room.fromContent"
            :label="labels.fromContent"
          />
        </span>
        <span class="sortable__actions">
          <UiButton
            :label="labels.edit"
            :aria-label="`${labels.edit}: ${item.label}`"
            size="sm"
            :data-room-edit="item.id"
            @click="open(item.room)"
          />
          <UiButton
            :label="labels.remove"
            :aria-label="`${labels.remove}: ${item.label}`"
            variant="ghost"
            size="sm"
            :data-room-remove="item.id"
            @click="removing = item.room"
          />
        </span>
      </template>
    </UiSortableList>
    <UiEmpty
      v-else
      icon="landmark"
      :title="labels.emptyTitle"
      :text="labels.emptyText"
    />

    <UiDrawer
      id="room-drawer"
      :title="editing?.room ? fill(labels.editTitle, { name: nameOf(editing.room) }) : labels.addTitle"
      :close-label="labels.close"
      :open="editing !== null"
      :busy="saving"
      @close="close"
    >
      <form
        v-if="form"
        id="room-form"
        ref="formElement"
        class="room-form"
        novalidate
        @submit.prevent="save"
      >
        <p
          v-if="alert"
          class="media-alert"
          role="alert"
        >
          {{ alert.message }}
          <a
            v-if="alert.reload"
            :href="reloadUrl"
          >{{ labels.reload }}</a>
        </p>
        <p
          v-for="message in generalErrors"
          :key="message"
          class="form-error"
          role="alert"
        >
          {{ message }}
        </p>
        <p class="form-hint">
          {{ labels.requiredHint }}
        </p>
        <template
          v-for="locale in locales"
          :key="locale.code"
        >
          <CmsField
            :id="`room-name-${locale.code}`"
            v-slot="{ describedBy, invalid }"
            :label="locales.length > 1 ? `${labels.name} (${locale.label})` : labels.name"
            :required="locale.code === defaultLocale"
            :error="fieldErrors[`names.${locale.code}`]?.[0]"
            :count="charCount(form.names[locale.code])"
            :max="nameMax"
          >
            <input
              :id="`room-name-${locale.code}`"
              v-model="form.names[locale.code]"
              type="text"
              :required="locale.code === defaultLocale"
              :autofocus="locale.code === defaultLocale"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
            >
          </CmsField>
          <CmsField
            :id="`room-description-${locale.code}`"
            v-slot="{ describedBy, invalid }"
            :label="locales.length > 1 ? `${labels.description} (${locale.label})` : labels.description"
            :error="fieldErrors[`descriptions.${locale.code}`]?.[0]"
            :count="charCount(form.descriptions[locale.code])"
            :max="descriptionMax"
          >
            <textarea
              :id="`room-description-${locale.code}`"
              v-model="form.descriptions[locale.code]"
              rows="4"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
            />
          </CmsField>
        </template>
        <div class="field-row">
          <CmsField
            id="room-capacity-min"
            v-slot="{ describedBy, invalid }"
            :label="labels.capacityMin"
            :error="fieldErrors.capacityMin?.[0]"
          >
            <input
              id="room-capacity-min"
              v-model="form.capacityMin"
              type="number"
              :disabled="layoutRange !== null"
              min="1"
              max="50000"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
            >
          </CmsField>
          <CmsField
            id="room-capacity-max"
            v-slot="{ describedBy, invalid }"
            :label="labels.capacityMaxField"
            :help="layoutRange !== null ? labels.capacityFromLayouts : undefined"
            :error="fieldErrors.capacityMax?.[0]"
          >
            <input
              id="room-capacity-max"
              v-model="form.capacityMax"
              type="number"
              :disabled="layoutRange !== null"
              min="1"
              max="50000"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
            >
          </CmsField>
        </div>
        <div class="field-row">
          <CmsField
            id="room-area"
            v-slot="{ describedBy, invalid }"
            :label="labels.areaField"
            :error="fieldErrors.areaM2?.[0]"
          >
            <input
              id="room-area"
              v-model="form.areaM2"
              type="number"
              min="1"
              max="50000"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
            >
          </CmsField>
          <CmsField
            id="room-height"
            v-slot="{ describedBy, invalid }"
            :label="labels.height"
            :help="labels.heightHelp"
            :error="fieldErrors.heightM?.[0]"
          >
            <input
              id="room-height"
              v-model.trim="form.heightM"
              type="text"
              inputmode="decimal"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
            >
          </CmsField>
        </div>
        <CmsField
          id="room-floor"
          v-slot="{ describedBy, invalid }"
          :label="labels.floor"
          :error="fieldErrors.floorMaterial?.[0]"
        >
          <select
            id="room-floor"
            v-model="form.floorMaterial"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
          >
            <option value="">
              {{ labels.floorNone }}
            </option>
            <option
              v-for="material in floorMaterials"
              :key="material.value"
              :value="material.value"
            >
              {{ material.label }}
            </option>
          </select>
        </CmsField>
        <div class="room-form__seating">
          <h3 class="room-form__heading">
            {{ labels.seating }}
          </h3>
          <fieldset
            v-for="type in seatingTypes"
            :key="type.value"
            class="field--group room-form__pair"
          >
            <legend class="field__label">
              {{ type.label }}
            </legend>
            <div class="field-row">
              <CmsField
                v-for="bound in ['min', 'max']"
                :id="`room-seating-${type.value}-${bound}`"
                :key="bound"
                v-slot="{ describedBy, invalid }"
                :label="bound === 'min' ? labels.seatingMin : labels.seatingMax"
                :error="fieldErrors[`seating.${type.value}.${bound}`]?.[0]"
              >
                <input
                  :id="`room-seating-${type.value}-${bound}`"
                  v-model="form.seating[type.value][bound]"
                  type="number"
                  min="1"
                  max="50000"
                  :aria-describedby="describedBy"
                  :aria-invalid="invalid"
                >
              </CmsField>
            </div>
          </fieldset>
        </div>
      </form>
      <template #footer>
        <UiButton
          :label="labels.cancel"
          @click="close"
        />
        <UiButton
          :label="labels.save"
          variant="primary"
          type="submit"
          form="room-form"
          :aria-disabled="saving ? 'true' : undefined"
        />
      </template>
    </UiDrawer>

    <UiModal
      id="room-remove"
      :title="labels.removeTitle"
      :close-label="labels.close"
      :open="removing !== null"
      danger
      @close="removing = null"
    >
      <template v-if="removing">
        <p>{{ fill(labels.removeText, { name: nameOf(removing) }) }}</p>
        <p v-if="removing.photoCount > 0">
          {{ fill(labels.removePhotos, { count: removing.photoCount }) }}
        </p>
      </template>
      <template #footer>
        <UiButton
          :label="labels.cancel"
          autofocus
          @click="removing = null"
        />
        <UiButton
          :label="labels.removeConfirm"
          variant="danger"
          data-confirm-remove
          @click="remove"
        />
      </template>
    </UiModal>
  </div>
</template>
