<script setup>
import { computed } from 'vue';

// A field of an island's form with the markup of templates/admin/form/theme.html.twig. The default slot gets
// {describedBy, invalid} for its control, whose id is this field's `id`.
const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    required: { type: Boolean, default: false },
    help: { type: String, default: undefined },
    error: { type: String, default: undefined },
    count: { type: Number, default: undefined },
    max: { type: Number, default: undefined },
});

const hasCounter = computed(() => props.max !== undefined);

const describedBy = computed(() => [
    props.help ? `${props.id}-help` : null,
    hasCounter.value ? `${props.id}-counter` : null,
    props.error ? `${props.id}-error` : null,
].filter(Boolean).join(' ') || undefined);
</script>

<template>
  <div class="field">
    <div class="field__label-row">
      <label :for="id">{{ label }}<span
        v-if="required"
        class="field__required"
        aria-hidden="true"
      >*</span></label>
      <span
        v-if="hasCounter"
        :id="`${id}-counter`"
        :class="['field__counter', count > max ? 'field__counter--over' : null]"
      >{{ count }} / {{ max }}</span>
    </div>
    <slot
      :described-by="describedBy"
      :invalid="error ? 'true' : undefined"
    />
    <span
      v-if="help"
      :id="`${id}-help`"
      class="field__help"
    >{{ help }}</span>
    <span
      v-if="error"
      :id="`${id}-error`"
      class="field__error"
    >{{ error }}</span>
  </div>
</template>
