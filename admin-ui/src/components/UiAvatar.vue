<script setup>
defineProps({
    initials: { type: String, required: true },
    size: { type: Number, default: 32 },
    square: { type: Boolean, default: false },
    label: { type: String, default: undefined },
});
</script>

<template>
  <span
    :class="['avatar', size !== 32 ? `avatar--${size}` : null, square ? 'avatar--square' : null]"
    :role="label ? 'img' : undefined"
    :aria-label="label"
    :aria-hidden="label ? undefined : 'true'"
  >{{ initials }}</span>
</template>
