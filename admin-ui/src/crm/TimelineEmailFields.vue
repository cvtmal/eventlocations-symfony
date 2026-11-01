<script setup>
import { computed, watch } from 'vue';
import { adminFetch } from '../api.js';

// The E-Mail tab's An, Vorlage, Betreff and Cc (Company.dc.html). A template is filled at pick time, so what is on
// screen is what goes out: picking one replaces subject and body, "Leer" leaves both alone (D2). The composer holds the
// values, because a send resets them and the fields leave the page with the tab.
const props = defineProps({
    kind: { type: String, required: true },
    recordId: { type: String, required: true },
    email: { type: Object, required: true },
    failure: { type: Object, default: null },
    labels: { type: Object, required: true },
});

const contactId = defineModel('contactId', { type: String, default: null });
const template = defineModel('template', { type: String, required: true });
const subject = defineModel('subject', { type: String, required: true });
const cc = defineModel('cc', { type: String, required: true });
const body = defineModel('body', { type: String, required: true });
// What a recipient change may replace (D3). A new recipient merges again only over this: staff's edits stay. The
// composer holds it with the values, or a tab change would leave text that no longer counts as untouched.
const lastDraft = defineModel('lastDraft', { type: Object, default: null });

const emit = defineEmits(['error']);

const severalRecipients = computed(() => props.email.recipients.length > 1);

const untouched = () => subject.value === lastDraft.value?.subject && body.value === lastDraft.value?.body;
const address = (recipient) => `${recipient.name} <${recipient.email}>`;
const fieldId = (name) => `composer-email-${name}`;

const invalid = (name) => (props.failure?.fields[name]
    ? { 'aria-invalid': 'true', 'aria-describedby': `${fieldId(name)}-error` }
    : {});

async function draft() {
    const asked = { template: template.value, contactId: contactId.value };
    // A slower answer must not put another template, or another recipient's name, into a mail about to go out.
    const stillWanted = () => asked.template === template.value && asked.contactId === contactId.value;
    const query = new URLSearchParams({
        kind: props.kind,
        recordId: props.recordId,
        template: asked.template,
        contact: asked.contactId ?? '',
    });

    // Until the answer arrives the text on screen counts as untouched, so a recipient picked in that gap merges again.
    lastDraft.value = { subject: subject.value, body: body.value };

    try {
        const answer = await adminFetch(`${props.email.urls.draft}?${query}`);

        if (stillWanted()) {
            subject.value = answer.subject;
            body.value = answer.body;
            lastDraft.value = { subject: answer.subject, body: answer.body };
        }
    } catch (error) {
        if (stillWanted()) {
            emit('error', error);
        }
    }
}

// Watched, not `@change`: a model set by a `select` reads the new value only once the composer has rendered it.
watch(template, (picked) => {
    if (picked !== '') {
        draft();
    }
});

watch(contactId, () => {
    if (template.value !== '' && untouched()) {
        draft();
    }
});
</script>

<template>
  <div class="crm-composer__email">
    <label :for="fieldId('contactId')">{{ labels.emailTo }}</label>
    <div>
      <select
        v-if="severalRecipients"
        :id="fieldId('contactId')"
        v-model="contactId"
        class="field__input"
        v-bind="invalid('contactId')"
      >
        <option
          v-for="recipient in email.recipients"
          :key="recipient.contactId ?? ''"
          :value="recipient.contactId"
        >
          {{ address(recipient) }}
        </option>
      </select>
      <output
        v-else
        :id="fieldId('contactId')"
        v-bind="invalid('contactId')"
      >{{ address(email.recipients[0]) }}</output>
      <p
        v-if="failure?.fields.contactId"
        :id="`${fieldId('contactId')}-error`"
        class="field__error"
      >
        {{ failure.fields.contactId }}
      </p>
    </div>
    <label :for="fieldId('template')">{{ labels.emailTemplate }}</label>
    <div>
      <select
        :id="fieldId('template')"
        v-model="template"
        class="field__input"
      >
        <option value="">
          {{ labels.emailNoTemplate }}
        </option>
        <option
          v-for="option in email.templates"
          :key="option.id"
          :value="option.id"
        >
          {{ option.name }}
        </option>
      </select>
    </div>
    <label :for="fieldId('subject')">{{ labels.emailSubject }}</label>
    <div>
      <input
        :id="fieldId('subject')"
        v-model="subject"
        class="field__input"
        type="text"
        v-bind="invalid('subject')"
      >
      <p
        v-if="failure?.fields.subject"
        :id="`${fieldId('subject')}-error`"
        class="field__error"
      >
        {{ failure.fields.subject }}
      </p>
    </div>
    <label :for="fieldId('cc')">{{ labels.emailCc }}</label>
    <div>
      <input
        :id="fieldId('cc')"
        v-model="cc"
        class="field__input"
        type="email"
        v-bind="invalid('cc')"
      >
      <p
        v-if="failure?.fields.cc"
        :id="`${fieldId('cc')}-error`"
        class="field__error"
      >
        {{ failure.fields.cc }}
      </p>
    </div>
  </div>
</template>
