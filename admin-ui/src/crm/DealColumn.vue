<script setup>
import { computed, ref } from 'vue';
import UiPill from '../components/UiPill.vue';
import DealCard from './DealCard.vue';
import { formatChf, groupDigits } from './money.js';

// A column of the board. `UiKanbanColumn` has no sum (D13), so the head reuses its classes. The count and the sum are
// the server's, kept by the island, because a capped column cannot be summed from its cards (N6).
const props = defineProps({
    column: { type: Object, required: true },
    next: { type: Object, default: null },
    targets: { type: Array, required: true },
    lostReasons: { type: Array, required: true },
    labels: { type: Object, required: true },
    draggedId: { type: String, default: null },
});

const emit = defineEmits(['move', 'drop', 'dragstart', 'dragend']);

const over = ref(false);

const hidden = computed(() => props.column.count - props.column.cards.length);

function onDragLeave(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        over.value = false;
    }
}

function onDrop() {
    over.value = false;
    emit('drop');
}
</script>

<template>
  <section
    :class="['kanban-column', over ? 'deal-column--over' : null]"
    :aria-label="column.label"
    :data-column="column.code"
    @dragenter.prevent="over = draggedId !== null"
    @dragover.prevent
    @dragleave="onDragLeave"
    @drop.prevent="onDrop"
  >
    <div class="kanban-column__head">
      <h2>{{ column.label }}</h2>
      <UiPill :label="groupDigits(column.count)" />
    </div>
    <span class="deal-column__sum">{{ labels.sum.replace('%sum', formatChf(column.sumRappen)) }}</span>
    <DealCard
      v-for="card in column.cards"
      :key="card.id"
      :card="card"
      :column="column"
      :next="next"
      :targets="targets"
      :lost-reasons="lostReasons"
      :labels="labels"
      :moving="draggedId === card.id"
      @move="emit('move', card, $event)"
      @dragstart="emit('dragstart', card)"
      @dragend="emit('dragend')"
    />
    <a
      v-if="hidden > 0"
      class="deal-column__more"
      :href="column.moreHref"
    >{{ labels.more.replace('%n', groupDigits(hidden)) }}</a>
  </section>
</template>
