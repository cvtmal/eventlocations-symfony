<script setup>
import { onMounted, ref, watch } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    closeLabel: { type: String, required: true },
    open: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
    variant: { type: String, default: undefined },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

function sync(open) {
    if (open && !dialog.value.open) {
        dialog.value.showModal();
    } else if (!open && dialog.value.open) {
        dialog.value.close();
    }
}

// The native close event also covers Esc and js/dialog.js's backdrop click.
function onClose() {
    if (props.open) {
        emit('close');
    }
}

onMounted(() => sync(props.open));
watch(() => props.open, sync, { flush: 'post' });
</script>

<template>
  <dialog
    :id="id"
    ref="dialog"
    :class="['modal', variant, danger ? 'modal--danger' : null]"
    :aria-labelledby="`${id}-title`"
    data-dialog
    @close="onClose"
  >
    <div class="modal__header">
      <h2
        :id="`${id}-title`"
        class="modal__title"
      >
        {{ title }}
      </h2>
      <form
        method="dialog"
        @submit.prevent="onClose"
      >
        <button
          class="btn btn--ghost btn--icon"
          :aria-label="closeLabel"
        >
          <UiIcon name="x" />
        </button>
      </form>
    </div>
    <div class="modal__body">
      <slot />
    </div>
    <div
      v-if="$slots.footer"
      class="modal__footer"
    >
      <slot name="footer" />
    </div>
  </dialog>
</template>
