<script setup>
import UiSortableList from '../components/UiSortableList.vue';

// Only on /admin/_styleguide: a real browser must show that focus survives a keyed move, which jsdom cannot.
defineOptions({ inheritAttrs: false });
</script>

<template>
  <UiSortableList v-bind="$attrs" />
</template>
