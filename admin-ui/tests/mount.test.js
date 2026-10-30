import { describe, expect, it, vi } from 'vitest';
import islands from '../src/islands.js';
import { mountIslands } from '../src/mount.js';

function root(html) {
    const element = document.createElement('div');
    element.innerHTML = html;

    return element;
}

function fakeCreateApp() {
    const mount = vi.fn();

    return { createApp: vi.fn(() => ({ mount })), mount };
}

describe('mountIslands', () => {
    it('mounts by name with the parsed props', async () => {
        const { createApp, mount } = fakeCreateApp();
        const component = { name: 'Hello' };
        const page = root(`<div data-island="hello" data-props='{"greeting":"Hallo"}'></div>`);

        await mountIslands(page, { hello: async () => ({ default: component }) }, createApp);

        expect(createApp).toHaveBeenCalledWith(component, { greeting: 'Hallo' });
        expect(mount).toHaveBeenCalledWith(page.firstElementChild);
    });

    it('defaults to empty props', async () => {
        const { createApp } = fakeCreateApp();

        await mountIslands(root('<div data-island="hello"></div>'), { hello: async () => ({ default: {} }) }, createApp);

        expect(createApp).toHaveBeenCalledWith({}, {});
    });

    it('skips an island that is already mounted', async () => {
        const { createApp } = fakeCreateApp();
        const page = root('<div data-island="hello"></div>');
        const registry = { hello: async () => ({ default: {} }) };

        await mountIslands(page, registry, createApp);
        await mountIslands(page, registry, createApp);

        expect(createApp).toHaveBeenCalledTimes(1);
    });

    it('skips a deferred island until the attribute is gone', async () => {
        const { createApp } = fakeCreateApp();
        const page = root('<div data-island="hello" data-island-defer="/admin-build/main.js"></div>');
        const registry = { hello: async () => ({ default: {} }) };

        await mountIslands(page, registry, createApp);
        expect(createApp).not.toHaveBeenCalled();

        page.firstElementChild.removeAttribute('data-island-defer');
        await mountIslands(page, registry, createApp);
        expect(createApp).toHaveBeenCalledTimes(1);
    });

    it('rejects an unknown name', async () => {
        const { createApp } = fakeCreateApp();

        await expect(mountIslands(root('<div data-island="nope"></div>'), {}, createApp)).rejects.toThrow('Unknown island "nope".');
    });
});

describe('the island registry', () => {
    it('names every file after its island, which IslandManifest relies on', () => {
        for (const [name, load] of Object.entries(islands)) {
            const pascal = name.split('-').map((part) => part[0].toUpperCase() + part.slice(1)).join('');

            expect(load.toString()).toContain(`/islands/${pascal}Island.vue`);
        }
    });
});
