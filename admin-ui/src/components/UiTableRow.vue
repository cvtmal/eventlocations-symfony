<script setup>
defineProps({
    selectName: { type: String, default: undefined },
    selectValue: { type: String, default: undefined },
    selectLabel: { type: String, default: undefined },
    // True when the table has an actions column: the last cell is printed even without actions (Twig's actions_column).
    actionsColumn: { type: Boolean, default: false },
});
</script>

<template>
  <tr>
    <td
      v-if="selectName"
      class="table__select"
    >
      <input
        type="checkbox"
        :name="selectName"
        :value="selectValue"
        :aria-label="selectLabel"
      >
    </td>
    <slot />
    <td
      v-if="actionsColumn || $slots.actions"
      class="table__actions"
    >
      <slot name="actions" />
    </td>
  </tr>
</template>
