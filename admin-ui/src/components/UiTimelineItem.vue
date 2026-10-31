<script setup>
import UiIcon from './UiIcon.vue';

defineProps({
    variant: { type: String, default: 'card' },
    icon: { type: String, required: true },
    tone: { type: String, default: undefined },
    title: { type: String, required: true },
    meta: { type: String, default: undefined },
    body: { type: String, default: undefined },
});
</script>

<template>
  <li :class="['timeline-item', `timeline-item--${variant}`]">
    <span :class="['timeline-item__icon', tone ? `timeline-item__icon--${tone}` : null]"><UiIcon :name="icon" /></span>
    <span v-if="variant === 'system'">{{ meta ? `${title} · ${meta}` : title }}</span>
    <div
      v-else
      class="timeline-item__content"
    >
      <div class="timeline-item__head">
        <span class="timeline-item__title">{{ title }}</span>
        <span
          v-if="meta"
          class="timeline-item__meta"
        >{{ meta }}</span>
      </div>
      <span
        v-if="body"
        class="muted"
      >{{ body }}</span>
      <slot />
    </div>
  </li>
</template>
