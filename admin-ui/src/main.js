import { createApp } from 'vue';
import islands from './islands.js';
import { mountIslands } from './mount.js';

mountIslands(document, islands, createApp);
