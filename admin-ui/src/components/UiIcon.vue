<script setup>
import { spriteUrl } from '../icons.js';

defineProps({
    name: { type: String, required: true },
    label: { type: String, default: undefined },
});
</script>

<template>
  <svg
    class="icon"
    :role="label ? 'img' : undefined"
    :aria-label="label"
    :aria-hidden="label ? undefined : 'true'"
  ><use :href="`${spriteUrl()}#${name}`" /></svg>
</template>
