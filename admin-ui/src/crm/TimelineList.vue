<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { adminFetch } from '../api.js';
import UiButton from '../components/UiButton.vue';
import UiEmpty from '../components/UiEmpty.vue';
import UiModal from '../components/UiModal.vue';
import UiPill from '../components/UiPill.vue';
import UiTimelineItem from '../components/UiTimelineItem.vue';
import { useToast } from '../composables/useToast.js';
import timelineTypes, { fallbackType } from '../timeline/types.js';
import { displayDate, fill, monthLabel, monthOf } from './entries.js';
import { formFailure } from './failure.js';

// The entries by month. Bodies are plain text through Vue's escaping, line breaks kept by CSS (D13), never `v-html`.
// Own entries can be edited and deleted (`canEdit`, D14), with no stale check: an entry has one author.
const SYSTEM_ICON = 'history';
const EDIT_INPUTS = ['body', 'occurredAt'];

const renderers = {
    text: (entry) => [entry.title, entry.body].filter(Boolean).join('\n'),
    // `title` is the venue's name. The couple's name goes with the purge, and then the venue is all that is left (N11).
    inquiry: (entry) => (entry.inquiryCouple ? fill(props.labels.inquiryEntry, { couple: entry.inquiryCouple, venue: entry.title }) : entry.title),
    // `title` is the subject. An anonymised entry has no `email` and never gets here (`bodyOf`).
    email: (entry) => [
        `${props.labels.emailTo}: ${entry.email.to}`,
        entry.email.cc ? `${props.labels.emailCc}: ${entry.email.cc}` : null,
        entry.title,
        entry.body ? `\n${entry.body}` : null,
    ].filter(Boolean).join('\n'),
};

const props = defineProps({
    entries: { type: Array, required: true },
    hasMore: { type: Boolean, required: true },
    loading: { type: Boolean, required: true },
    filtered: { type: Boolean, required: true },
    types: { type: Array, required: true },
    activitiesUrl: { type: String, required: true },
    inquiriesUrl: { type: String, required: true },
    labels: { type: Object, required: true },
    locale: { type: String, required: true },
});

const emit = defineEmits(['changed', 'removed', 'more']);

const toast = useToast({ dismissLabel: props.labels.close });

const editing = ref(null);
const draftBody = ref('');
const draftDate = ref('');
const saving = ref(false);
const editFailure = ref(null);
const deleting = ref(null);
const deleteBusy = ref(false);
const deleteFailure = ref(null);
const list = ref(null);

const reloadHref = window.location.href.split('#')[0];

const months = computed(() => {
    const groups = new Map();
    for (const entry of props.entries) {
        const month = monthOf(entry.occurredAt);
        groups.set(month, [...(groups.get(month) ?? []), entry]);
    }

    return [...groups].map(([month, entries]) => ({ month, label: monthLabel(month, props.locale), entries }));
});

const typeOf = (entry) => timelineTypes[entry.type] ?? fallbackType;

function titleOf(entry) {
    const type = entry.email?.status === 'failed'
        ? props.labels.emailTitleFailed
        : props.types.find((known) => known.code === entry.type)?.label ?? entry.type;

    return entry.actorName ? fill(props.labels.by, { type, actor: entry.actorName }) : type;
}

function bodyOf(entry) {
    return entry.anonymised ? props.labels.anonymised : renderers[typeOf(entry).renderer](entry);
}

function systemTitle(entry) {
    if (entry.stage) {
        const label = entry.actorName ? props.labels.system[entry.type] : props.labels.dealStageChangedAnonymous;

        return fill(label, { actor: entry.actorName, deal: entry.title, stage: props.labels.stages[entry.stage] ?? entry.stage });
    }

    return fill(props.labels.system[entry.type], { venue: entry.venueName ?? '', tier: props.labels.tiers[entry.tier] ?? '' });
}

// A stage change names its actor in the sentence (Company.dc.html).
function systemMeta(entry) {
    return [entry.stage ? null : entry.actorName, displayDate(entry.occurredAt)].filter(Boolean).join(' · ');
}

const editButtonId = (id) => `timeline-edit-${id}`;

async function startEdit(entry) {
    editing.value = entry.id;
    draftBody.value = entry.body ?? '';
    draftDate.value = entry.occurredAt;
    editFailure.value = null;
    await nextTick();
    list.value?.querySelector('.crm-timeline__edit textarea')?.focus();
}

async function stopEdit() {
    const id = editing.value;
    editing.value = null;
    editFailure.value = null;
    await nextTick();
    document.getElementById(editButtonId(id))?.focus();
}

function cancelEdit() {
    if (!saving.value) {
        stopEdit();
    }
}

async function saveEdit() {
    if (saving.value) {
        return;
    }

    saving.value = true;
    editFailure.value = null;

    try {
        const answer = await adminFetch(`${props.activitiesUrl}/${editing.value}`, {
            method: 'PATCH',
            body: { body: draftBody.value, occurredAt: draftDate.value },
        });
        emit('changed', answer.entry);
        await stopEdit();
        // An entry moved behind the last loaded one has left the list: the button went with it.
        if (document.activeElement === document.body) {
            list.value?.focus();
        }
    } catch (error) {
        editFailure.value = formFailure(error, props.labels, EDIT_INPUTS);
    } finally {
        saving.value = false;
    }
}

function askDelete(entry) {
    deleting.value = entry;
    deleteFailure.value = null;
}

async function confirmDelete() {
    deleteBusy.value = true;
    deleteFailure.value = null;

    try {
        await adminFetch(`${props.activitiesUrl}/${deleting.value.id}`, { method: 'DELETE' });
        emit('removed', deleting.value.id);
        deleting.value = null;
        toast.show(props.labels.deleted);
        await nextTick();
        list.value?.focus();
    } catch (error) {
        deleteFailure.value = formFailure(error, props.labels, []);
    } finally {
        deleteBusy.value = false;
    }
}

// The last page takes "Mehr laden" with it, and the focus must not fall back to the top of the document.
watch(() => props.hasMore, (hasMore) => {
    if (!hasMore && document.activeElement === document.body) {
        list.value?.focus();
    }
}, { flush: 'post' });
</script>

<template>
  <div
    ref="list"
    class="crm-timeline__list"
    tabindex="-1"
  >
    <UiEmpty
      v-if="entries.length === 0 && !loading"
      icon="history"
      :title="filtered ? labels.emptyFiltered : labels.empty"
    />
    <template
      v-for="group in months"
      :key="group.month"
    >
      <h2 class="crm-timeline__month">
        {{ group.label }}
      </h2>
      <ol class="timeline">
        <template
          v-for="entry in group.entries"
          :key="entry.id"
        >
          <UiTimelineItem
            v-if="entry.kind === 'system'"
            variant="system"
            :icon="SYSTEM_ICON"
            :title="systemTitle(entry)"
            :meta="systemMeta(entry)"
          />
          <UiTimelineItem
            v-else
            :icon="typeOf(entry).icon"
            :tone="typeOf(entry).tone"
            :title="titleOf(entry)"
            :meta="displayDate(entry.occurredAt)"
            :body="editing === entry.id ? undefined : bodyOf(entry)"
          >
            <form
              v-if="editing === entry.id"
              class="crm-timeline__edit"
              novalidate
              @submit.prevent="saveEdit"
              @keydown.esc.prevent="cancelEdit"
            >
              <textarea
                v-model="draftBody"
                class="field__input"
                rows="3"
                :aria-label="labels.editBody"
                :aria-invalid="editFailure?.fields.body ? 'true' : undefined"
                :aria-describedby="editFailure?.fields.body ? 'timeline-edit-body-error' : undefined"
              />
              <p
                v-if="editFailure?.fields.body"
                id="timeline-edit-body-error"
                class="field__error"
              >
                {{ editFailure.fields.body }}
              </p>
              <div class="crm-composer__field">
                <label for="timeline-edit-date">{{ labels.date }}</label>
                <input
                  id="timeline-edit-date"
                  v-model="draftDate"
                  class="field__input"
                  type="datetime-local"
                  :aria-invalid="editFailure?.fields.occurredAt ? 'true' : undefined"
                  :aria-describedby="editFailure?.fields.occurredAt ? 'timeline-edit-date-error' : undefined"
                >
              </div>
              <p
                v-if="editFailure?.fields.occurredAt"
                id="timeline-edit-date-error"
                class="field__error"
              >
                {{ editFailure.fields.occurredAt }}
              </p>
              <p
                v-if="editFailure?.message"
                class="field__error"
                role="alert"
              >
                {{ editFailure.message }}
                <a
                  v-if="editFailure.reload"
                  :href="reloadHref"
                >{{ labels.reload }}</a>
              </p>
              <div class="crm-timeline__actions">
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
                  @click="cancelEdit"
                />
              </div>
            </form>
            <div
              v-else-if="entry.canEdit"
              class="crm-timeline__actions"
            >
              <UiButton
                :id="editButtonId(entry.id)"
                :label="labels.edit"
                variant="ghost"
                size="xs"
                @click="startEdit(entry)"
              />
              <UiButton
                :label="labels.delete"
                variant="ghost"
                size="xs"
                @click="askDelete(entry)"
              />
            </div>
            <a
              v-if="entry.inquiryId && !entry.anonymised"
              class="crm-timeline__link"
              :href="`${inquiriesUrl}/${entry.inquiryId}`"
            >{{ labels.inquiryOpen }}</a>
            <UiPill
              v-if="entry.email"
              class="crm-timeline__pill"
              :label="entry.email.status === 'sent' ? labels.emailStatusSent : labels.emailStatusFailed"
              :tone="entry.email.status === 'sent' ? 'green' : 'red'"
            />
          </UiTimelineItem>
        </template>
      </ol>
    </template>
    <UiButton
      v-if="hasMore"
      class="crm-timeline__more"
      :label="labels.more"
      :aria-busy="loading ? 'true' : undefined"
      @click="emit('more')"
    />

    <UiModal
      id="timeline-delete"
      :title="labels.deleteTitle"
      :close-label="labels.close"
      :open="deleting !== null"
      :busy="deleteBusy"
      danger
      @close="deleting = null"
    >
      <p>{{ labels.deleteText }}</p>
      <p
        v-if="deleteFailure"
        class="field__error"
        role="alert"
      >
        {{ deleteFailure.message }}
        <a
          v-if="deleteFailure.reload"
          :href="reloadHref"
        >{{ labels.reload }}</a>
      </p>
      <template #footer>
        <UiButton
          :label="labels.cancel"
          autofocus
          @click="deleting = null"
        />
        <UiButton
          :label="labels.deleteConfirm"
          variant="danger"
          @click="confirmDelete"
        />
      </template>
    </UiModal>
  </div>
</template>
