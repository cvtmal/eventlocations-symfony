<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { adminFetch } from '../api.js';
import TimelineComposer from '../crm/TimelineComposer.vue';
import TimelineList from '../crm/TimelineList.vue';
import { isNewer } from '../crm/entries.js';
import { failureOf } from '../crm/failure.js';

// The middle column of a company or deal record (CRM-3, CRM-4, D12): filter tabs, composer, list. The first page
// comes with the props (P6), so opening a record costs no request. `kind` and `recordId` name the record (the lead's
// D1); #28 adds a `deal` kind in PHP and nothing here. Quick actions `[data-composer-tab]` in the left card are
// outside the island, hence the document listener. Props: `Crm\TimelineProps`.
const props = defineProps({
    kind: { type: String, required: true },
    recordId: { type: String, required: true },
    page: { type: Object, required: true }, // {entries, next}
    types: { type: Array, required: true }, // [{code, label, tab}]
    composer: { type: Array, required: true }, // [{code, label, placeholder, submit}]
    assignees: { type: Array, required: true }, // [{id, name}]
    viewerId: { type: String, required: true },
    urls: { type: Object, required: true }, // {activities, timeline, tasks}
    labels: { type: Object, required: true },
    locale: { type: String, required: true },
    email: { type: Object, default: null }, // {recipients, templates, urls}; null on a deal
});

const entries = ref(props.page.entries);
const next = ref(props.page.next);
// code => name of the tiers. The server reads them when a page holds a change of tier (F12), so the first page's props
// have them or not, and a later page brings its own.
const tiers = ref({ ...props.labels.tiers });
const listLabels = computed(() => ({ ...props.labels, tiers: tiers.value }));
// The list on screen: set only when its page arrives, so the active tab and the list never disagree.
const filter = ref(null);
const loading = ref(false);
const failure = ref(null);
const composerForm = ref(null);
const reloadHref = window.location.href.split('#')[0];

const filters = [{ code: null, tab: props.labels.all }, ...props.types];

let running = null;

async function load(type, before) {
    running?.abort();
    const controller = new AbortController();
    running = controller;
    loading.value = true;
    failure.value = null;

    const query = new URLSearchParams();
    if (type !== null) {
        query.set('type', type);
    }
    if (before !== null) {
        query.set('before', before);
    }
    const search = query.toString();

    try {
        const answer = await adminFetch(search === '' ? props.urls.timeline : `${props.urls.timeline}?${search}`, { signal: controller.signal });
        const known = new Set(before === null ? [] : entries.value.map((entry) => entry.id));
        entries.value = [...(before === null ? [] : entries.value), ...answer.entries.filter((entry) => !known.has(entry.id))];
        next.value = answer.next;
        if (answer.tiers) {
            tiers.value = { ...tiers.value, ...answer.tiers };
        }
        filter.value = type;
    } catch (error) {
        if (error.name !== 'AbortError') {
            failure.value = failureOf(error, props.labels);
        }
    } finally {
        if (running === controller) {
            running = null;
            loading.value = false;
        }
    }
}

function selectFilter(code) {
    if (code !== filter.value) {
        load(code, null);
    }
}

function loadMore() {
    if (!loading.value) {
        load(filter.value, next.value);
    }
}

// A written entry goes where the server would put it. Behind the last loaded one it stays out while there are
// more pages: "Mehr laden" brings it, and the keyset would otherwise bring it a second time.
function place(entry) {
    const others = entries.value.filter((other) => other.id !== entry.id);
    const last = others.at(-1);
    const shown = (filter.value === null || filter.value === entry.type)
        && (next.value === null || last === undefined || isNewer(entry, last));

    entries.value = shown ? [...others, entry].sort((a, b) => (isNewer(a, b) ? -1 : 1)) : others;
}

function remove(id) {
    entries.value = entries.value.filter((entry) => entry.id !== id);
}

function onQuickAction(event) {
    const button = event.target instanceof Element ? event.target.closest('[data-composer-tab]') : null;
    if (button) {
        composerForm.value?.open(button.dataset.composerTab);
    }
}

onMounted(() => document.addEventListener('click', onQuickAction));
onBeforeUnmount(() => {
    document.removeEventListener('click', onQuickAction);
    running?.abort();
});
</script>

<template>
  <section
    class="crm-timeline"
    :aria-label="labels.section"
  >
    <nav
      class="tabs crm-timeline__filters"
      :aria-label="labels.filters"
    >
      <button
        v-for="item in filters"
        :key="item.code ?? 'all'"
        type="button"
        class="tab crm-timeline__filter"
        :class="{ 'tab--active': item.code === filter }"
        :aria-pressed="item.code === filter ? 'true' : 'false'"
        @click="selectFilter(item.code)"
      >
        {{ item.tab }}
      </button>
    </nav>

    <TimelineComposer
      ref="composerForm"
      :kind="kind"
      :record-id="recordId"
      :tabs="composer"
      :assignees="assignees"
      :viewer-id="viewerId"
      :urls="urls"
      :labels="labels"
      :email="email"
      @logged="place"
    />

    <p
      v-if="failure"
      class="field__error"
      role="alert"
    >
      {{ failure.message }}
      <a
        v-if="failure.reload"
        :href="reloadHref"
      >{{ labels.reload }}</a>
    </p>

    <TimelineList
      :entries="entries"
      :has-more="next !== null"
      :loading="loading"
      :filtered="filter !== null"
      :types="types"
      :activities-url="urls.activities"
      :inquiries-url="urls.inquiries"
      :labels="listLabels"
      :locale="locale"
      @changed="place"
      @removed="remove"
      @more="loadMore"
    />
  </section>
</template>
