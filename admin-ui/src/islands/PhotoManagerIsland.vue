<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ValidationFailed } from '../api.js';
import CmsField from '../cms/CmsField.vue';
import { createQueue } from '../cms/queue.js';
import { charCount, failureOf, fill } from '../cms/text.js';
import { uploadFile } from '../cms/upload.js';
import { createWrites, freshToken } from '../cms/writes.js';
import UiButton from '../components/UiButton.vue';
import UiDropzone from '../components/UiDropzone.vue';
import UiEmpty from '../components/UiEmpty.vue';
import UiIcon from '../components/UiIcon.vue';
import UiModal from '../components/UiModal.vue';
import UiPill from '../components/UiPill.vue';
import UiSortableList from '../components/UiSortableList.vue';
import { useToast } from '../composables/useToast.js';

// The Fotos tab (#25, CMS-6). Props: templates/admin/cms/venue/photos.html.twig. Every write answers the full list
// (PF10), which replaces `photos`, tokens included. Unsaved text lives in `drafts`, one per photo, so an upload's
// answer or picking another tile never touches what staff typed.
const props = defineProps({
    photos: { type: Array, required: true },
    rooms: { type: Array, required: true },
    locales: { type: Array, required: true },
    defaultLocale: { type: String, required: true },
    venueName: { type: String, required: true },
    maxBytes: { type: Number, required: true },
    accept: { type: String, required: true },
    altMax: { type: Number, required: true },
    idPlaceholder: { type: String, required: true },
    endpoints: { type: Object, required: true },
    reloadUrl: { type: String, required: true },
    labels: { type: Object, required: true },
});

const toast = useToast({ dismissLabel: props.labels.close });

const photos = ref(props.photos);
const selectedId = ref(null);
const drafts = ref({});
const fieldErrors = ref({});
const alert = ref(null);
const saving = ref(false);
const confirming = ref(false);
const uploads = ref([]);
const halted = ref(false);
const panel = ref(null);
const panelStart = ref(null);
const queueSection = ref(null);
const headerStale = ref(false);

const writes = createWrites('photos', (list) => {
    if (list) {
        alert.value = null; // Whatever it warned of, a write went through since.
    }
    photos.value = list ?? [...photos.value];
});

const queue = createQueue({
    maxBytes: props.maxBytes,
    upload: (file, onProgress) => writes.run(() => uploadFile(props.endpoints.upload, file, { onProgress })),
    onChange: (state) => {
        uploads.value = state.items;
        halted.value = state.halted;
    },
});

const url = (template, id) => template.replace(props.idPlaceholder, id);
const byPosition = (a, b) => a.position - b.position;

function inScope(id, list = photos.value) {
    return list.filter(({ roomId }) => roomId === id).sort(byPosition);
}

const scopes = computed(() => [
    { id: null, name: props.labels.gallery },
    ...[...props.rooms].sort(byPosition),
].map((scope) => {
    const scoped = inScope(scope.id);

    return {
        id: scope.id,
        name: scope.name,
        count: fill(scoped.length === 1 ? props.labels.countOne : props.labels.countOther, { count: scoped.length }),
        // A new array on every change of `photos`: UiSortableList resets its optimistic order from it.
        items: scoped.map((photo, index) => ({ id: photo.id, label: fill(props.labels.item, { position: index + 1 }), photo })),
    };
}));

const selected = computed(() => photos.value.find(({ id }) => id === selectedId.value) ?? null);
const draft = computed(() => (selected.value ? drafts.value[selected.value.id] : null));

// The position within the photo's own scope, which is what the panel and the fallback alt text show.
const selectedPosition = computed(() => inScope(selected.value?.roomId ?? null).findIndex(({ id }) => id === selectedId.value) + 1);
const isLastInGallery = computed(() => selected.value?.roomId === null && inScope(null).length === 1);
const cover = computed(() => inScope(null)[0] ?? null);
const waitingFiles = computed(() => uploads.value.filter(({ state }) => state === 'waiting').map(({ name }) => name).join(', '));

// The page header is Twig's, and its <picture> keeps the cover of the page load. From the first change on, the
// Teleport below replaces its children.
watch(() => cover.value?.id, () => {
    const media = document.querySelector('.record-header__media');

    if (media && !headerStale.value) {
        media.replaceChildren();
        headerStale.value = true;
    }
});

function variantsOf({ formats, widths }) {
    return fill(props.labels.detailsVariants, {
        formats: formats.map((format) => format.toUpperCase()).join(', '),
        min: Math.min(...widths),
        max: Math.max(...widths),
    });
}

function draftOf(photo) {
    return {
        alts: Object.fromEntries(props.locales.map(({ code }) => [code, photo.alts[code] ?? ''])),
        roomId: photo.roomId,
    };
}

function altChanged(photo, candidate) {
    return props.locales.some(({ code }) => candidate.alts[code] !== (photo.alts[code] ?? ''));
}

function unsaved() {
    return photos.value.some((photo) => {
        const candidate = drafts.value[photo.id];

        return candidate && (altChanged(photo, candidate) || candidate.roomId !== photo.roomId);
    });
}

// The panel is the next stop for a keyboard, past every tile's move buttons. It grows when it renders, so it is
// measured after that: at 390 px it sits under the grid, and only the whole of it is of use.
async function select(photo) {
    drafts.value[photo.id] ??= draftOf(photo);
    selectedId.value = photo.id;
    fieldErrors.value = {};
    await nextTick();
    panelStart.value?.focus({ preventScroll: true });
    panel.value?.scrollIntoView?.({ block: 'nearest' });
}

// A queue button takes itself away, and focus must not fall to <body>.
async function onQueueButton(action, id) {
    action(id);
    await nextTick();
    (queueSection.value ?? document.getElementById('photo-upload'))?.focus();
}

function queueText(item) {
    if (item.state === 'done') {
        return item.duplicate ? props.labels.queueDuplicate : props.labels.queueDone;
    }
    if (item.state === 'refused') {
        return item.message ?? (item.reason === 'tooLarge' ? props.labels.queueTooLarge : props.labels.queueFailed);
    }

    return {
        waiting: props.labels.queueWaiting,
        sending: props.labels.queueSending,
        processing: props.labels.queueProcessing,
        failed: props.labels.queueFailed,
    }[item.state];
}

function failed(error) {
    if (error instanceof ValidationFailed) {
        const known = props.locales.map(({ code }) => `alts.${code}`).concat('roomId');
        const general = Object.keys(error.errors).filter((field) => !known.includes(field));

        fieldErrors.value = error.errors;
        alert.value = general.length ? { message: error.errors[general[0]][0], reload: false } : null;

        return;
    }

    alert.value = failureOf(error, props.labels);
}

// The server wants exactly the scope's ids, and a reorder can wait behind an upload: staff's order of what is still
// there, then whatever joined since, which is where the server put it.
function orderedIds(scope, ids, list) {
    const fresh = inScope(scope.id, list).map(({ id }) => id);

    return [...ids.filter((id) => fresh.includes(id)), ...fresh.filter((id) => !ids.includes(id))];
}

async function reorder(scope, ids) {
    try {
        await writes.send(props.endpoints.order, (held) => ({
            method: 'PUT',
            body: { roomId: scope.id, ids: orderedIds(scope, ids, held ?? photos.value) },
        }));
    } catch (error) {
        const failure = failureOf(error, props.labels);

        if (failure.reload) {
            alert.value = failure;
        } else {
            toast.show(props.labels.orderFailed, 'error');
        }
    }
}

// D21: alt text and room are two requests, sent only for what changed. The alt save goes first, as it carries the
// row token that the room move would bump.
async function save() {
    const photo = selected.value;
    const candidate = draft.value;
    const requests = [];

    if (altChanged(photo, candidate)) {
        requests.push([props.endpoints.alt, (held) => ({
            loadedAt: freshToken(held ?? photos.value, photo),
            alts: Object.fromEntries(Object.entries(candidate.alts).map(([code, text]) => [code, text.trim()])),
        })]);
    }
    if (candidate.roomId !== photo.roomId) {
        requests.push([props.endpoints.room, () => ({ roomId: candidate.roomId })]);
    }
    if (requests.length === 0) {
        return;
    }

    saving.value = true;
    fieldErrors.value = {};
    alert.value = null;

    try {
        let answer = null;

        for (const [template, body] of requests) {
            answer = await writes.send(url(template, photo.id), (held) => ({ method: 'PUT', body: body(held) }));
        }

        drafts.value[photo.id] = draftOf(answer.photos.find(({ id }) => id === photo.id));
        toast.show(props.labels.saved);
    } catch (error) {
        failed(error);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    const { id } = selected.value;

    confirming.value = false;
    alert.value = null;

    try {
        await writes.send(url(props.endpoints.remove, id), { method: 'DELETE' });
        delete drafts.value[id];
        selectedId.value = null;
        toast.show(props.labels.removed);
        await nextTick();
        panelStart.value?.focus(); // The button that opened the modal has left the document.
    } catch (error) {
        failed(error);
    }
}

function warnBeforeLeaving(event) {
    if (queue.running() || unsaved()) {
        event.preventDefault();
        event.returnValue = props.labels.leaveWarning;
    }
}

onMounted(() => window.addEventListener('beforeunload', warnBeforeLeaving));
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeLeaving));
</script>

<template>
  <div class="photo-manager">
    <div class="photo-manager__main">
      <UiDropzone
        id="photo-upload"
        :label="labels.dropLabel"
        :hint="labels.dropHint"
        :accept="accept"
        multiple
        @files="queue.add"
      />

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

      <section
        v-if="uploads.length"
        ref="queueSection"
        class="card photo-queue"
        :aria-label="labels.queueLabel"
        tabindex="-1"
      >
        <div
          v-if="halted"
          class="photo-queue__halted"
          role="alert"
        >
          <span>{{ fill(labels.queueSessionGone, { files: waitingFiles }) }}</span>
          <a :href="reloadUrl">{{ labels.reload }}</a>
        </div>
        <ul class="photo-queue__list">
          <li
            v-for="item in uploads"
            :key="item.id"
            class="progress photo-queue__item"
          >
            <span class="progress__label">{{ item.name }}</span>
            <progress
              v-if="item.state === 'waiting'"
              class="progress__bar"
              value="0"
              max="1"
              :aria-label="item.name"
            />
            <progress
              v-else-if="item.state === 'sending'"
              class="progress__bar"
              :value="item.loaded"
              :max="item.total"
              :aria-label="item.name"
            />
            <progress
              v-else-if="item.state === 'processing'"
              class="progress__bar photo-queue__bar--busy"
              :aria-label="item.name"
            />
            <span
              :class="['photo-queue__state', item.state === 'failed' || item.state === 'refused' ? 'photo-queue__state--error' : null]"
              aria-live="polite"
            >{{ queueText(item) }}</span>
            <UiButton
              v-if="item.state === 'failed'"
              :label="labels.queueRetry"
              :aria-label="`${labels.queueRetry}: ${item.name}`"
              size="sm"
              @click="onQueueButton(queue.retry, item.id)"
            />
            <UiButton
              v-if="item.state === 'failed' || item.state === 'refused'"
              :label="labels.queueDismiss"
              :aria-label="`${labels.queueDismiss}: ${item.name}`"
              variant="ghost"
              size="sm"
              @click="onQueueButton(queue.dismiss, item.id)"
            />
          </li>
        </ul>
      </section>

      <section
        v-for="scope in scopes"
        :key="scope.id ?? 'gallery'"
        class="photo-scope"
      >
        <div class="photo-scope__head">
          <h2 class="photo-scope__title">
            {{ scope.name }} <span class="photo-scope__count">· {{ scope.count }}</span>
          </h2>
          <span
            v-if="scope.id === null"
            class="photo-scope__hint"
          >{{ labels.sortHint }}</span>
        </div>
        <UiSortableList
          v-if="scope.items.length"
          class="photo-grid"
          :items="scope.items"
          :label="fill(labels.listLabel, { scope: scope.name })"
          :up-label="labels.moveUp"
          :down-label="labels.moveDown"
          :moved-label="labels.moved"
          @reorder="reorder(scope, $event)"
        >
          <template #item="{ item, index }">
            <button
              type="button"
              class="photo-tile"
              :aria-pressed="item.id === selectedId ? 'true' : 'false'"
              @click="select(item.photo)"
            >
              <span
                class="photo-tile__frame"
                :style="{ backgroundColor: item.photo.dominantColor }"
              >
                <img
                  :src="item.photo.thumbnailUrl"
                  :width="item.photo.width"
                  :height="item.photo.height"
                  alt=""
                  loading="lazy"
                  draggable="false"
                >
              </span>
              <span class="photo-tile__meta">
                <span
                  class="photo-tile__position"
                  aria-hidden="true"
                >{{ index + 1 }}</span>
                <span class="visually-hidden">{{ fill(labels.item, { position: index + 1 }) }}</span>
                <UiPill
                  v-if="scope.id === null && index === 0"
                  :label="labels.cover"
                  tone="dark"
                />
                <UiPill
                  v-if="!item.photo.alts[defaultLocale]"
                  :label="labels.altMissing"
                  tone="amber"
                />
              </span>
            </button>
          </template>
        </UiSortableList>
        <UiEmpty
          v-else-if="scope.id === null"
          icon="upload"
          :title="labels.emptyTitle"
          :text="labels.emptyText"
        />
        <p
          v-else
          class="photo-scope__empty"
        >
          {{ labels.roomEmpty }}
        </p>
      </section>
    </div>

    <aside
      ref="panel"
      class="card photo-panel"
      :aria-label="labels.detailsLabel"
    >
      <template v-if="selected">
        <h2
          ref="panelStart"
          class="photo-panel__title"
          tabindex="-1"
        >
          {{ fill(labels.detailsTitle, { position: selectedPosition }) }}
        </h2>
        <span
          class="photo-panel__frame"
          :style="{ backgroundColor: selected.dominantColor }"
        >
          <img
            :src="selected.thumbnailUrl"
            :width="selected.width"
            :height="selected.height"
            alt=""
          >
        </span>
        <span class="photo-panel__facts">{{ fill(labels.detailsSize, { width: selected.width, height: selected.height }) }} · {{ variantsOf(selected) }}</span>
        <CmsField
          v-for="locale in locales"
          :id="`photo-alt-${locale.code}`"
          :key="locale.code"
          v-slot="{ describedBy, invalid }"
          :label="fill(labels.altLabel, { locale: locale.label })"
          :help="fill(labels.altFallback, { position: selectedPosition })"
          :error="fieldErrors[`alts.${locale.code}`]?.[0]"
          :count="charCount(draft.alts[locale.code])"
          :max="altMax"
        >
          <textarea
            :id="`photo-alt-${locale.code}`"
            v-model="draft.alts[locale.code]"
            rows="3"
            :placeholder="labels.altPlaceholder"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
          />
        </CmsField>
        <CmsField
          id="photo-room"
          v-slot="{ describedBy, invalid }"
          :label="labels.roomLabel"
          :error="fieldErrors.roomId?.[0]"
        >
          <select
            id="photo-room"
            v-model="draft.roomId"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
          >
            <option :value="null">
              {{ labels.galleryOption }}
            </option>
            <option
              v-for="room in rooms"
              :key="room.id"
              :value="room.id"
            >
              {{ room.name }}
            </option>
          </select>
        </CmsField>
        <div class="photo-panel__actions">
          <UiButton
            :label="labels.remove"
            variant="danger"
            @click="confirming = true"
          />
          <UiButton
            :label="labels.save"
            variant="primary"
            :aria-disabled="saving ? 'true' : undefined"
            @click="saving || save()"
          />
        </div>
      </template>
      <p
        v-else
        ref="panelStart"
        class="photo-panel__none"
        tabindex="-1"
      >
        {{ labels.detailsNone }}
      </p>
    </aside>

    <Teleport
      v-if="headerStale"
      to=".record-header__media"
    >
      <img
        v-if="cover"
        :src="cover.thumbnailUrl"
        alt=""
      >
      <span
        v-else
        class="record-header__placeholder"
      ><UiIcon name="landmark" /></span>
    </Teleport>

    <UiModal
      id="photo-remove"
      :title="labels.removeTitle"
      :close-label="labels.close"
      :open="confirming"
      danger
      @close="confirming = false"
    >
      <p>{{ isLastInGallery ? labels.removeLast : labels.removeText }}</p>
      <template #footer>
        <UiButton
          :label="labels.cancel"
          autofocus
          @click="confirming = false"
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
