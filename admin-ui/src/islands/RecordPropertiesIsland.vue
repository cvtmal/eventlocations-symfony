<script setup>
import { ref } from 'vue';
import PropertyRow from '../crm/PropertyRow.vue';

// The property card of a company or deal record (D10): one row per field, each saving on its own. A save
// also dispatches `crm:property-saved` ({name, value, display}) from the card, so the page can follow a rename.
defineProps({
    url: { type: String, required: true },
    fields: { type: Array, required: true },
    labels: { type: Object, required: true },
    idPrefix: { type: String, default: 'property' },
});

const root = ref(null);

function announce(detail) {
    root.value?.dispatchEvent(new CustomEvent('crm:property-saved', { bubbles: true, detail }));
}
</script>

<template>
  <div
    ref="root"
    class="record-properties"
  >
    <PropertyRow
      v-for="field in fields"
      :key="field.name"
      :url="url"
      :field="field"
      :labels="labels"
      :id-prefix="idPrefix"
      @saved="announce"
    />
  </div>
</template>
