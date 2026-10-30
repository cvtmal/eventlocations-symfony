// Hotspot: one line per island. The name is the data-island value; the file is src/islands/<Pascal>Island.vue,
// which IslandManifest relies on to find the chunk.
export default {
    hello: () => import('./islands/HelloIsland.vue'),
};
