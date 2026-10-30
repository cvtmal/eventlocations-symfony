<script setup>
defineProps({
    variant: { type: String, default: 'line' },
});
</script>

<template>
  <span
    :class="['skeleton', `skeleton--${variant}`]"
    aria-hidden="true"
  />
</template>
