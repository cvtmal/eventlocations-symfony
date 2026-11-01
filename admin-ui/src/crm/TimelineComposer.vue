<script setup>
import { computed, nextTick, ref } from 'vue';
import { adminFetch } from '../api.js';
import UiButton from '../components/UiButton.vue';
import { useToast } from '../composables/useToast.js';
import { nowInZurich } from './entries.js';
import { formFailure } from './failure.js';
import TimelineEmailFields from './TimelineEmailFields.vue';

// "Neue Aktivität" (Company.dc.html): one tab per composable type, plus Aufgabe. An entry is added by the island; a
// task reloads the page, because the Aufgaben card is server-rendered and islands share no store (D20). On a record
// with an address the E-Mail tab sends (CRM-10) and can still log by hand; without one it only logs. Enter never
// sends: that tab's buttons are `type="button"` and `submit()` returns (D5). What is typed stays through a tab change.
const TASK = 'task';
const EMAIL = 'email_logged';
const ACTIVITY_INPUTS = ['body', 'occurredAt'];
const TASK_INPUTS = ['title', 'dueOn', 'assigneeId'];
// `value` stays out: another company's contact is answered there, and `formFailure` shows it as the form's message.
const EMAIL_INPUTS = ['contactId', 'cc', 'subject', 'body'];

const props = defineProps({
    kind: { type: String, required: true },
    recordId: { type: String, required: true },
    tabs: { type: Array, required: true },
    assignees: { type: Array, required: true },
    viewerId: { type: String, required: true },
    urls: { type: Object, required: true },
    labels: { type: Object, required: true },
    email: { type: Object, default: null },
});

const emit = defineEmits(['logged']);

const toast = useToast({ dismissLabel: props.labels.close });

const selected = ref(props.tabs[0].code);
const text = ref('');
const occurredAt = ref(nowInZurich());
// The field shows now until someone changes it, and a page open for hours still logs the moment of the save.
const dateChanged = ref(false);
const dueOn = ref(nowInZurich().slice(0, 10));
const assigneeId = ref(props.viewerId);
const contactId = ref(props.email?.recipients[0]?.contactId ?? null);
const template = ref('');
const subject = ref('');
const cc = ref('');
const lastDraft = ref(null);
const saving = ref(false);
// From a saved entry until the form is touched: the second press of a double click finds an empty field, which is
// nothing to save, not a mistake to mark in red beside the toast.
const justSaved = ref(false);
const failure = ref(null);
const textField = ref(null);
const root = ref(null);

const reloadHref = window.location.href.split('#')[0];

const current = computed(() => props.tabs.find((tab) => tab.code === selected.value));
const isTask = computed(() => selected.value === TASK);
const isEmail = computed(() => selected.value === EMAIL && props.email?.recipients.length > 0);
const noAddress = computed(() => selected.value === EMAIL && props.email !== null && props.email.recipients.length === 0);
const textError = computed(() => failure.value?.fields[isTask.value ? 'title' : 'body']);

const tabId = (code) => `composer-tab-${code}`;

function select(code) {
    selected.value = code;
    failure.value = null;
    justSaved.value = false;
}

async function open(code) {
    if (!props.tabs.some((tab) => tab.code === code)) {
        return;
    }

    select(code);
    await nextTick();
    root.value?.scrollIntoView?.({ block: 'nearest' });
    textField.value?.focus();
}

defineExpose({ open });

function reset() {
    text.value = '';
    subject.value = '';
    cc.value = '';
    template.value = '';
    lastDraft.value = null;
    occurredAt.value = nowInZurich();
    dateChanged.value = false;
}

async function logActivity() {
    const answer = await adminFetch(props.urls.activities, {
        method: 'POST',
        body: {
            kind: props.kind,
            recordId: props.recordId,
            type: selected.value,
            body: text.value,
            occurredAt: dateChanged.value ? occurredAt.value : nowInZurich(),
        },
    });

    reset();
    justSaved.value = true;
    emit('logged', answer.entry);
    toast.show(props.labels.saved);
}

async function createTask() {
    await adminFetch(props.urls.tasks, {
        method: 'POST',
        body: { kind: props.kind, recordId: props.recordId, title: text.value, dueOn: dueOn.value, assigneeId: assigneeId.value },
    });

    window.location.reload();

    // `reload()` returns at once and this page stays live until the new one arrives, so `saving` stays set: a second
    // press in that gap would create the task again.
    return true;
}

async function sendEmail() {
    const answer = await adminFetch(props.email.urls.send, {
        method: 'POST',
        body: {
            kind: props.kind,
            recordId: props.recordId,
            contactId: contactId.value,
            cc: cc.value,
            subject: subject.value,
            body: text.value,
        },
    });

    // A failed mail is an entry too: it lands on the timeline, and the text stays for another try.
    emit('logged', answer.entry);

    if (answer.entry.email.status === 'failed') {
        failure.value = { fields: {}, message: props.labels.emailSendFailed, reload: false };

        return;
    }

    reset();
    justSaved.value = true;
    toast.show(props.labels.emailSent);
}

// `action` answers true when `saving` has to stay set.
async function run(action, inputs) {
    if (saving.value || justSaved.value) {
        return;
    }

    saving.value = true;
    failure.value = null;

    try {
        if (await action()) {
            return;
        }
    } catch (error) {
        failure.value = formFailure(error, props.labels, inputs);
    }

    saving.value = false;
}

async function submit() {
    if (isEmail.value) {
        return;
    }

    if (isTask.value) {
        await run(createTask, TASK_INPUTS);

        return;
    }

    await run(logActivity, ACTIVITY_INPUTS);
}

function draftFailed(error) {
    failure.value = formFailure(error, props.labels, EMAIL_INPUTS);
}
</script>

<template>
  <form
    ref="root"
    class="card crm-composer"
    :aria-label="labels.composer"
    novalidate
    @input="justSaved = false"
    @submit.prevent="submit"
  >
    <div
      class="seg"
      role="tablist"
      :aria-label="labels.composerTabs"
    >
      <button
        v-for="tab in tabs"
        :id="tabId(tab.code)"
        :key="tab.code"
        type="button"
        class="seg__item"
        role="tab"
        :aria-selected="tab.code === selected ? 'true' : 'false'"
        :tabindex="tab.code === selected ? 0 : -1"
        @click="select(tab.code)"
      >
        {{ tab.label }}
      </button>
    </div>
    <div
      class="crm-composer__panel"
      role="tabpanel"
      :aria-labelledby="tabId(selected)"
    >
      <TimelineEmailFields
        v-if="isEmail"
        v-model:contact-id="contactId"
        v-model:template="template"
        v-model:subject="subject"
        v-model:cc="cc"
        v-model:body="text"
        v-model:last-draft="lastDraft"
        :kind="kind"
        :record-id="recordId"
        :email="email"
        :failure="failure"
        :labels="labels"
        @error="draftFailed"
      />
      <input
        v-if="isTask"
        ref="textField"
        v-model="text"
        class="field__input"
        type="text"
        :aria-label="current.label"
        :placeholder="current.placeholder"
        :aria-invalid="textError ? 'true' : undefined"
        :aria-describedby="textError ? 'composer-text-error' : undefined"
      >
      <textarea
        v-else
        ref="textField"
        v-model="text"
        class="field__input"
        :rows="isEmail ? 5 : 3"
        :aria-label="isEmail ? labels.emailBody : current.label"
        :placeholder="isEmail ? undefined : current.placeholder"
        :aria-invalid="textError ? 'true' : undefined"
        :aria-describedby="textError ? 'composer-text-error' : undefined"
      />
      <p
        v-if="textError"
        id="composer-text-error"
        class="field__error"
      >
        {{ textError }}
      </p>
      <p
        v-if="isEmail"
        class="field__help"
      >
        {{ labels.emailHint }}
      </p>
      <p
        v-if="noAddress"
        class="field__help"
      >
        {{ labels.emailNoAddress }}
      </p>
      <div class="crm-composer__foot">
        <div class="crm-composer__fields">
          <template v-if="isTask">
            <div class="crm-composer__field">
              <label for="composer-due-on">{{ labels.dueOn }}</label>
              <input
                id="composer-due-on"
                v-model="dueOn"
                class="field__input"
                type="date"
                :aria-invalid="failure?.fields.dueOn ? 'true' : undefined"
                :aria-describedby="failure?.fields.dueOn ? 'composer-due-on-error' : undefined"
              >
            </div>
            <div class="crm-composer__field">
              <label for="composer-assignee">{{ labels.assignee }}</label>
              <select
                id="composer-assignee"
                v-model="assigneeId"
                class="field__input"
                :aria-invalid="failure?.fields.assigneeId ? 'true' : undefined"
                :aria-describedby="failure?.fields.assigneeId ? 'composer-assignee-error' : undefined"
              >
                <option
                  v-for="person in assignees"
                  :key="person.id"
                  :value="person.id"
                >
                  {{ person.name }}
                </option>
              </select>
            </div>
          </template>
          <div
            v-else
            class="crm-composer__field"
          >
            <label for="composer-date">{{ labels.date }}</label>
            <input
              id="composer-date"
              v-model="occurredAt"
              class="field__input"
              type="datetime-local"
              :aria-invalid="failure?.fields.occurredAt ? 'true' : undefined"
              :aria-describedby="failure?.fields.occurredAt ? 'composer-date-error' : undefined"
              @input="dateChanged = true"
            >
          </div>
        </div>
        <div
          v-if="isEmail"
          class="crm-composer__actions"
        >
          <UiButton
            :label="current.submit"
            size="sm"
            :aria-busy="saving ? 'true' : undefined"
            @click="run(logActivity, ACTIVITY_INPUTS)"
          />
          <UiButton
            :label="labels.emailSend"
            variant="primary"
            size="sm"
            :aria-busy="saving ? 'true' : undefined"
            @click="run(sendEmail, EMAIL_INPUTS)"
          />
        </div>
        <UiButton
          v-else
          :label="current.submit"
          variant="primary"
          size="sm"
          type="submit"
          :aria-busy="saving ? 'true' : undefined"
        />
      </div>
      <p
        v-if="failure?.fields.occurredAt"
        id="composer-date-error"
        class="field__error"
      >
        {{ failure.fields.occurredAt }}
      </p>
      <p
        v-if="failure?.fields.dueOn"
        id="composer-due-on-error"
        class="field__error"
      >
        {{ failure.fields.dueOn }}
      </p>
      <p
        v-if="failure?.fields.assigneeId"
        id="composer-assignee-error"
        class="field__error"
      >
        {{ failure.fields.assigneeId }}
      </p>
      <p
        v-if="failure?.message"
        class="field__error"
        role="alert"
      >
        {{ failure.message }}
        <a
          v-if="failure.reload"
          :href="reloadHref"
        >{{ labels.reload }}</a>
      </p>
    </div>
  </form>
</template>
