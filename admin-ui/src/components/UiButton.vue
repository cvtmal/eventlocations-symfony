<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    label: { type: String, required: true },
    variant: { type: String, default: 'secondary' },
    size: { type: String, default: undefined },
    icon: { type: String, default: undefined },
    iconOnly: { type: Boolean, default: false },
    href: { type: String, default: undefined },
    type: { type: String, default: 'button' },
});

const classes = computed(() => [
    'btn',
    props.variant !== 'secondary' ? `btn--${props.variant}` : null,
    props.size ? `btn--${props.size}` : null,
    props.iconOnly ? 'btn--icon' : null,
]);
</script>

<template>
  <component
    :is="href ? 'a' : 'button'"
    :class="classes"
    :href="href"
    :type="href ? undefined : type"
    :aria-label="iconOnly ? label : undefined"
  >
    <UiIcon
      v-if="icon"
      :name="icon"
    />
    <template v-if="!iconOnly">
      {{ label }}
    </template>
  </component>
</template>
