// Hotspot: one line per island. The name is the data-island value; the file is src/islands/<Pascal>Island.vue,
// which IslandManifest relies on to find the chunk.
export default {
    hello: () => import('./islands/HelloIsland.vue'),
    'command-palette': () => import('./islands/CommandPaletteIsland.vue'),
    combobox: () => import('./islands/ComboboxIsland.vue'),
    'styleguide-sortable': () => import('./islands/StyleguideSortableIsland.vue'),
};
