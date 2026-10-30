<script setup>
import UiCombobox from '../components/UiCombobox.vue';

defineOptions({ inheritAttrs: false });
</script>

<template>
  <UiCombobox v-bind="$attrs" />
</template>
