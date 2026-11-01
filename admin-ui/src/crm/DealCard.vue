<script setup>
import { computed } from 'vue';
import UiButton from '../components/UiButton.vue';
import UiKanbanCard from '../components/UiKanbanCard.vue';
import UiPill from '../components/UiPill.vue';
import { formatChf } from './money.js';

// A deal on the board (Pipeline.dc.html). Three ways to move it, one event: drag, "Weiter →", and "Verschieben nach", a
// native popover with `.dropdown` that `assets/js/admin/dropdown.js` places. It is no `role="menu"`, as `_dropdown`.
const TIER_TONES = { beliebte_location: 'pink', empfehlung: 'amber', top_premium: 'blue', premium: 'green', basic: 'grey' };

const props = defineProps({
    card: { type: Object, required: true },
    column: { type: Object, required: true },
    next: { type: Object, default: null }, // the column "Weiter" leads to
    targets: { type: Array, required: true }, // the other columns
    lostReasons: { type: Array, required: true },
    labels: { type: Object, required: true },
    moving: { type: Boolean, default: false },
});

const emit = defineEmits(['move', 'dragstart', 'dragend']);

const menuId = `deal-move-${props.card.id}`;

const day = (date) => date.split('-').reverse().join('.');

const amount = computed(() => (props.card.amountRappen === null ? '' : props.labels.sum.replace('%sum', formatChf(props.card.amountRappen))));
const ownerLabel = computed(() => (props.card.ownerName ? props.labels.owner.replace('%name', props.card.ownerName) : undefined));
const nextLabel = computed(() => (props.next ? props.labels.nextTo.replace('%deal', props.card.name).replace('%stage', props.next.label) : ''));

const dateText = computed(() => {
    const { card, column, labels } = props;
    if (column.isWon) {
        return card.closedOn ? labels.wonOn.replace('%date', day(card.closedOn)) : '';
    }
    if (column.isLost) {
        const reason = props.lostReasons.find(({ value }) => value === card.lostReason);

        return reason ? labels.lostBecause.replace('%reason', reason.label) : '';
    }

    return card.expectedCloseOn ? labels.closesOn.replace('%date', day(card.expectedCloseOn)) : '';
});

function onDragStart(event) {
    event.dataTransfer?.setData('text/plain', props.card.id);
    emit('dragstart');
}

function moveTo(event, code) {
    event.target.closest('[popover]')?.hidePopover?.();
    emit('move', code);
}
</script>

<template>
  <UiKanbanCard
    :class="['deal-card', moving ? 'deal-card--moving' : null]"
    :data-deal="card.id"
    :title="card.name"
    :href="card.href"
    :meta="card.company"
    :owner-initials="card.ownerInitials ?? undefined"
    :owner-label="ownerLabel"
    draggable="true"
    @dragstart="onDragStart"
    @dragend="emit('dragend')"
  >
    <div
      v-if="card.tier || amount"
      class="deal-card__facts"
    >
      <UiPill
        v-if="card.tier"
        :label="card.tier.label"
        :tone="TIER_TONES[card.tier.code] ?? 'grey'"
      />
      <span
        v-if="amount"
        class="deal-card__amount"
      >{{ amount }}</span>
    </div>
    <div class="deal-card__foot">
      <span class="deal-card__date">{{ dateText }}</span>
      <div class="deal-card__moves">
        <UiButton
          v-if="next"
          :label="labels.next"
          :aria-label="nextLabel"
          variant="ghost"
          size="sm"
          data-deal-focus
          @click="emit('move', next.code)"
        />
        <UiButton
          :label="labels.moveOf.replace('%deal', card.name)"
          variant="ghost"
          size="sm"
          icon="ellipsis"
          icon-only
          :popovertarget="menuId"
          :data-deal-focus="next ? undefined : ''"
        />
        <div
          :id="menuId"
          class="dropdown"
          popover
        >
          <ul class="dropdown__list">
            <li
              v-for="target in targets"
              :key="target.code"
            >
              <button
                type="button"
                class="dropdown__item"
                :data-deal-target="target.code"
                @click="moveTo($event, target.code)"
              >
                {{ target.label }}
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </UiKanbanCard>
</template>
