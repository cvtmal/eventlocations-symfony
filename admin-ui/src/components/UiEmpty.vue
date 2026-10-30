<script setup>
import UiIcon from './UiIcon.vue';

defineProps({
    icon: { type: String, required: true },
    title: { type: String, required: true },
    text: { type: String, default: undefined },
});
</script>

<template>
  <div class="empty">
    <UiIcon :name="icon" />
    <strong class="empty__title">{{ title }}</strong>
    <span
      v-if="text"
      class="empty__text"
    >{{ text }}</span>
  </div>
</template>
