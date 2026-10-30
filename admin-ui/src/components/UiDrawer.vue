<script setup>
import UiModal from './UiModal.vue';

defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    closeLabel: { type: String, required: true },
    open: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
});

defineEmits(['close']);
</script>

<template>
  <UiModal
    :id="id"
    :title="title"
    :close-label="closeLabel"
    :open="open"
    :danger="danger"
    variant="drawer"
    @close="$emit('close')"
  >
    <slot />
    <template
      v-if="$slots.footer"
      #footer
    >
      <slot name="footer" />
    </template>
  </UiModal>
</template>
