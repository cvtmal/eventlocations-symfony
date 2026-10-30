<script setup>
import { nextTick, ref, watch } from 'vue';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';

// Native drag events plus move buttons, which are the keyboard and WCAG 2.5.7 alternative (ADM-7, research D10).
const props = defineProps({
    items: { type: Array, required: true }, // list<{id, label}>
    label: { type: String, required: true },
    upLabel: { type: String, required: true },
    downLabel: { type: String, required: true },
    movedLabel: { type: String, required: true }, // holds %label and %position
});

const emit = defineEmits(['reorder']);

const order = ref([...props.items]);
const dragged = ref(null);
const announcement = ref('');
const list = ref(null);

watch(() => props.items, (items) => {
    order.value = [...items];
});

function place(from, to) {
    const next = [...order.value];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);
    order.value = next;

    announcement.value = props.movedLabel.replace('%label', item.label).replace('%position', String(to + 1));
    emit('reorder', next.map(({ id }) => id));
}

// A keyed move takes the button out of the document, which drops focus.
async function move(index, delta, direction) {
    const target = index + delta;
    if (target < 0 || target >= order.value.length) {
        return;
    }

    const { id } = order.value[index];
    place(index, target);
    await nextTick();
    list.value.querySelector(`[data-move="${direction}-${id}"]`)?.focus();
}

function onDrop(index) {
    if (dragged.value !== null && dragged.value !== index) {
        place(dragged.value, index);
    }
    dragged.value = null;
}
</script>

<template>
  <div>
    <ol
      ref="list"
      class="sortable"
      :aria-label="label"
    >
      <li
        v-for="(item, index) in order"
        :key="item.id"
        :class="['sortable__item', index === dragged ? 'sortable__item--moving' : null]"
        draggable="true"
        @dragstart="dragged = index"
        @dragover.prevent
        @drop.prevent="onDrop(index)"
        @dragend="dragged = null"
      >
        <span class="sortable__grip"><UiIcon name="grip-vertical" /></span>
        <span class="sortable__label">{{ item.label }}</span>
        <div class="sortable__moves">
          <UiButton
            :label="`${upLabel}: ${item.label}`"
            size="sm"
            icon="arrow-up"
            icon-only
            :data-move="`up-${item.id}`"
            :aria-disabled="index === 0 ? 'true' : undefined"
            @click="move(index, -1, 'up')"
          />
          <UiButton
            :label="`${downLabel}: ${item.label}`"
            size="sm"
            icon="arrow-down"
            icon-only
            :data-move="`down-${item.id}`"
            :aria-disabled="index === order.length - 1 ? 'true' : undefined"
            @click="move(index, 1, 'down')"
          />
        </div>
      </li>
    </ol>
    <p
      class="visually-hidden"
      role="status"
    >
      {{ announcement }}
    </p>
  </div>
</template>
