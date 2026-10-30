<script setup>
import UiAvatar from './UiAvatar.vue';

defineProps({
    title: { type: String, required: true },
    href: { type: String, required: true },
    meta: { type: String, default: undefined },
    ownerInitials: { type: String, default: undefined },
    ownerLabel: { type: String, default: undefined },
});
</script>

<template>
  <article class="kanban-card">
    <div class="kanban-card__head">
      <a
        class="kanban-card__title"
        :href="href"
      >{{ title }}</a>
      <UiAvatar
        v-if="ownerInitials"
        :initials="ownerInitials"
        :size="24"
        :label="ownerLabel"
      />
    </div>
    <span
      v-if="meta"
      class="kanban-card__meta"
    >{{ meta }}</span>
    <slot />
  </article>
</template>
