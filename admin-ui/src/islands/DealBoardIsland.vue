<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { adminFetch } from '../api.js';
import UiButton from '../components/UiButton.vue';
import UiModal from '../components/UiModal.vue';
import DealColumn from '../crm/DealColumn.vue';
import { failureOf } from '../crm/failure.js';
import { formatChf, groupDigits } from '../crm/money.js';

// The pipeline (CRM-7, ADM-7, D12–D14). Drag, "Weiter →" and "Verschieben nach" share one move: optimistic, rolled back
// on failure, `expected` the stage the card showed, so the first mover wins and the second gets a reload link (D17-b).
// Moving to a lost stage asks for the reason first. A won move whose answer carries a `tierOffer` asks whether to apply
// the tier: a native form that posts and comes back here (D20). Heads start from the server's totals, because a capped
// column cannot be summed from its cards, and each move adds to them (N6). Props: `Crm\DealBoardProps`.
const props = defineProps({
    columns: { type: Array, required: true }, // [{code, label, isWon, isLost, count, sumRappen, moreHref, cards}]
    lostReasons: { type: Array, required: true }, // [{value, label}]
    labels: { type: Object, required: true },
});

const columns = ref(props.columns);
const announcement = ref('');
const failure = ref(null);
const dragged = ref(null);
const lostPrompt = ref(null);
const lostReason = ref('');
const lostFailure = ref('');
const lostField = ref(null);
const tierPrompt = ref(null);
const board = ref(null);
const reloadHref = window.location.href.split('#')[0];
const returnTo = window.location.pathname + window.location.search;

// A second move of a card before the first has answered would send a stale `expected`.
const inFlight = new Set();

const lostColumn = computed(() => columns.value.find(({ isLost }) => isLost));
const lostText = computed(() => props.labels.lostText.replace('%deal', lostPrompt.value?.card.name ?? ''));

const openFigure = computed(() => {
    const open = columns.value.filter(({ isWon, isLost }) => !isWon && !isLost);
    const count = open.reduce((total, column) => total + column.count, 0);
    const sum = open.reduce((total, column) => total + column.sumRappen, 0);
    const label = count === 1 ? props.labels.openOne : props.labels.openOther;

    return label.replace('%sum', formatChf(sum)).replace('%n', groupDigits(count));
});

// The head sits in the page, outside the island.
watch(openFigure, (text) => {
    const figure = document.querySelector('[data-deal-open]');
    if (figure) {
        figure.textContent = text;
    }
});

const columnOf = (code) => columns.value.find((column) => column.code === code);

// The next stage, capped at won, and none from won or lost (`Pipeline.dc.html`).
function nextOf(column) {
    if (column.isWon || column.isLost) {
        return null;
    }

    return columns.value.slice(columns.value.indexOf(column) + 1).find(({ isLost }) => !isLost) ?? null;
}

const targetsOf = (column) => columns.value.filter(({ code }) => code !== column.code);

function place(card, from, to, index) {
    from.cards.splice(from.cards.indexOf(card), 1);
    to.cards.splice(index, 0, card);
    from.count -= 1;
    to.count += 1;
    from.sumRappen -= card.amountRappen ?? 0;
    to.sumRappen += card.amountRappen ?? 0;
}

// A keyed move takes the focused button out of the document.
async function refocus(card) {
    await nextTick();
    board.value?.querySelector(`[data-deal="${card.id}"] [data-deal-focus]`)?.focus();
}

function ask(card, from) {
    lostReason.value = '';
    lostFailure.value = '';
    lostPrompt.value = { card, from };
}

async function move(card, fromCode, toCode, { reason = null, keyboard = true } = {}) {
    const from = columnOf(fromCode);
    const to = columnOf(toCode);
    if (from === to || inFlight.has(card.id)) {
        return;
    }
    if (to.isLost && reason === null) {
        ask(card, from.code);

        return;
    }

    const index = from.cards.indexOf(card);
    inFlight.add(card.id);
    failure.value = null;
    announcement.value = '';
    place(card, from, to, 0);
    if (keyboard) {
        refocus(card);
    }

    try {
        const answer = await adminFetch(card.stageUrl, {
            method: 'PATCH',
            body: { stage: to.code, expected: from.code, lostReason: reason },
        });
        card.closedOn = answer.closedOn;
        card.lostReason = reason;
        announcement.value = props.labels.moved.replace('%deal', card.name).replace('%stage', to.label);
        tierPrompt.value = answer.tierOffer ?? null;
    } catch (error) {
        place(card, to, from, index);
        failure.value = failureOf(error, props.labels);
        announcement.value = props.labels.movedBack.replace('%deal', card.name).replace('%stage', from.label);
        if (keyboard) {
            refocus(card);
        }
    } finally {
        inFlight.delete(card.id);
    }
}

function onDrop(target) {
    if (dragged.value !== null) {
        move(dragged.value.card, dragged.value.from, target.code, { keyboard: false });
    }
    dragged.value = null;
}

function closePrompt() {
    lostPrompt.value = null;
}

async function confirmLost() {
    if (lostReason.value === '') {
        lostFailure.value = props.labels.lostRequired;
        await nextTick();
        lostField.value?.focus();

        return;
    }

    const { card, from } = lostPrompt.value;
    closePrompt();
    move(card, from, lostColumn.value.code, { reason: lostReason.value });
}
</script>

<template>
  <div
    ref="board"
    class="deal-board"
  >
    <p
      v-if="failure"
      class="form-error"
      role="alert"
    >
      {{ failure.message }}
      <a
        v-if="failure.reload"
        :href="reloadHref"
      >{{ labels.reload }}</a>
    </p>
    <div class="kanban">
      <DealColumn
        v-for="column in columns"
        :key="column.code"
        :column="column"
        :next="nextOf(column)"
        :targets="targetsOf(column)"
        :lost-reasons="lostReasons"
        :labels="labels"
        :dragged-id="dragged?.card.id ?? null"
        @move="(card, code) => move(card, column.code, code)"
        @drop="onDrop(column)"
        @dragstart="dragged = { card: $event, from: column.code }"
        @dragend="dragged = null"
      />
    </div>
    <p
      class="visually-hidden"
      role="status"
    >
      {{ announcement }}
    </p>
    <UiModal
      id="deal-lost"
      :title="labels.lostTitle"
      :close-label="labels.lostClose"
      :open="lostPrompt !== null"
      @close="closePrompt"
    >
      <form
        id="deal-lost-form"
        class="form"
        novalidate
        @submit.prevent="confirmLost"
      >
        <p>{{ lostText }}</p>
        <div class="field">
          <label for="deal-lost-reason">{{ labels.lostReason }}</label>
          <select
            id="deal-lost-reason"
            ref="lostField"
            v-model="lostReason"
            class="field__input"
            autofocus
            :aria-invalid="lostFailure ? 'true' : undefined"
            :aria-describedby="lostFailure ? 'deal-lost-error' : undefined"
            @change="lostFailure = ''"
          >
            <option value="">
              {{ labels.lostChoose }}
            </option>
            <option
              v-for="reason in lostReasons"
              :key="reason.value"
              :value="reason.value"
            >
              {{ reason.label }}
            </option>
          </select>
          <p
            v-if="lostFailure"
            id="deal-lost-error"
            class="field__error"
            role="alert"
          >
            {{ lostFailure }}
          </p>
        </div>
      </form>
      <template #footer>
        <UiButton
          :label="labels.lostCancel"
          variant="ghost"
          @click="closePrompt"
        />
        <UiButton
          :label="labels.lostConfirm"
          variant="primary"
          type="submit"
          form="deal-lost-form"
        />
      </template>
    </UiModal>
    <UiModal
      id="deal-tier"
      :title="labels.tierTitle"
      :close-label="labels.tierClose"
      :open="tierPrompt !== null"
      @close="tierPrompt = null"
    >
      <form
        id="deal-tier-form"
        method="post"
        :action="tierPrompt?.action"
      >
        <input
          type="hidden"
          name="_token"
          :value="tierPrompt?.token"
        >
        <input
          type="hidden"
          name="expected"
          :value="tierPrompt?.expected"
        >
        <input
          type="hidden"
          name="return_to"
          :value="returnTo"
        >
        <p>{{ tierPrompt?.text }}</p>
      </form>
      <template #footer>
        <UiButton
          :label="labels.tierCancel"
          variant="ghost"
          @click="tierPrompt = null"
        />
        <UiButton
          :label="labels.tierConfirm"
          variant="primary"
          type="submit"
          form="deal-tier-form"
        />
      </template>
    </UiModal>
  </div>
</template>
