<script setup>
import UiPill from './UiPill.vue';

defineProps({
    title: { type: String, required: true },
    count: { type: String, required: true },
});
</script>

<template>
  <section
    class="kanban-column"
    :aria-label="title"
  >
    <div class="kanban-column__head">
      <h2>{{ title }}</h2>
      <UiPill :label="count" />
    </div>
    <slot />
  </section>
</template>
