import { createApp } from 'vue';
import islands from './islands.js';
import { mountIslands } from './mount.js';

// An event, not an export: Vite's app builds set preserveEntrySignatures to false, so the built entry
// would keep no export. palette-trigger.js dispatches it after loading this file.
const mount = () => mountIslands(document, islands, createApp);

mount();
document.addEventListener('admin:mount-islands', mount);
