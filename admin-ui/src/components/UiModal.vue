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
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const dialog = ref(null);
let focusBeforeBusy = null;

function sync(open) {
    if (open && !dialog.value.open) {
        dialog.value.showModal();
    } else if (!open && dialog.value.open) {
        dialog.value.close();
    }
}

function onCancel(event) {
    if (props.busy) {
        event.preventDefault();
    }
}

// The native close event also covers Esc and js/dialog.js's backdrop click. While busy the dialog comes straight
// back: a second Esc skips a prevented cancel (the browser's close watcher), and the backdrop click has no cancel.
function onClose() {
    if (props.busy) {
        if (!dialog.value.open) {
            dialog.value.showModal();
        }
    } else if (props.open) {
        emit('close');
    }
}

onMounted(() => sync(props.open));
watch(() => props.open, sync, { flush: 'post' });

// inert drops focus to <body>, so it goes back where it was unless the owner has moved it since (a field error).
watch(() => props.busy, (busy) => {
    if (busy) {
        focusBeforeBusy = document.activeElement;
    }
}, { flush: 'pre' });
watch(() => props.busy, (busy) => {
    if (!busy && dialog.value.open && !dialog.value.contains(document.activeElement)) {
        focusBeforeBusy?.focus();
    }
}, { flush: 'post' });
</script>

<template>
  <dialog
    :id="id"
    ref="dialog"
    :class="['modal', variant, danger ? 'modal--danger' : null]"
    :aria-labelledby="`${id}-title`"
    :aria-busy="busy ? 'true' : undefined"
    data-dialog
    @cancel="onCancel"
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
        :inert="busy || undefined"
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
    <div
      class="modal__body"
      :inert="busy || undefined"
    >
      <slot />
    </div>
    <div
      v-if="$slots.footer"
      class="modal__footer"
      :inert="busy || undefined"
    >
      <slot name="footer" />
    </div>
  </dialog>
</template>
