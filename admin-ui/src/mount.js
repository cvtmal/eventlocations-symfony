// Mounts every island below `root` once. Kept apart from main.js so tests can pass their own registry.
// A deferred island waits until palette-trigger.js removes its attribute.
export function mountIslands(root, registry, createApp) {
    const mounts = [...root.querySelectorAll('[data-island]:not([data-island-mounted]):not([data-island-defer])')].map((element) => {
        const name = element.dataset.island;
        const load = registry[name];
        if (!load) {
            return Promise.reject(new Error(`Unknown island "${name}".`));
        }

        element.dataset.islandMounted = 'true';
        const props = JSON.parse(element.dataset.props ?? '{}');

        return load().then((module) => createApp(module.default, props).mount(element));
    });

    return Promise.all(mounts);
}
