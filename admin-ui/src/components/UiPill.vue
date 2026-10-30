<script setup>
defineProps({
    label: { type: String, required: true },
    tone: { type: String, default: 'grey' },
    dot: { type: Boolean, default: false },
});
</script>

<template>
  <span :class="['pill', `pill--${tone}`]">
    <span
      v-if="dot"
      class="pill__dot"
    />
    {{ label }}
  </span>
</template>
