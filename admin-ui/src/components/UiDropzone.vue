<script setup>
import UiIcon from './UiIcon.vue';

defineProps({
    id: { type: String, required: true },
    name: { type: String, default: undefined },
    label: { type: String, required: true },
    hint: { type: String, default: undefined },
    accept: { type: String, default: undefined },
    multiple: { type: Boolean, default: false },
});

const emit = defineEmits(['files']);

function onChange(event) {
    emit('files', [...event.target.files]);
    event.target.value = '';
}
</script>

<template>
  <div class="dropzone">
    <input
      :id="id"
      class="dropzone__input"
      type="file"
      :name="name"
      :accept="accept"
      :multiple="multiple"
      :aria-describedby="hint ? `${id}-hint` : undefined"
      @change="onChange"
    >
    <UiIcon name="upload" />
    <label
      class="dropzone__label"
      :for="id"
    >{{ label }}</label>
    <span
      v-if="hint"
      :id="`${id}-hint`"
      class="dropzone__hint"
    >{{ hint }}</span>
  </div>
</template>
