<script setup>
import { ref } from 'vue';

defineProps({
    greeting: { type: String, required: true },
    countLabel: { type: String, required: true },
    buttonLabel: { type: String, required: true },
});

const count = ref(0);
</script>

<template>
  <div>
    <p>{{ greeting }}</p>
    <p aria-live="polite">
      {{ countLabel }}: {{ count }}
    </p>
    <button
      type="button"
      class="btn"
      @click="count++"
    >
      {{ buttonLabel }}
    </button>
  </div>
</template>
