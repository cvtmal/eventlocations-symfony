// Opens the deferred command palette (#23): a page without an island loads no Vue (ADM-8), so the palette
// arrives on first use. The one exception to "no import" in .claude/rules/frontend.md: the entry's URL is
// a variable from the manifest, which AssetMapper's compiler leaves alone. Once mounted, the island owns
// the click and the shortcut, and this file finds nothing to do.
const isShortcut = (event) => event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey;

const open = (event) => {
    const mount = document.querySelector('[data-island-defer]');
    if (!mount) {
        return;
    }

    event.preventDefault();
    const entry = mount.dataset.islandDefer;
    mount.removeAttribute('data-island-defer');

    // On a page that already loaded the entry the import does nothing, so the event does the mounting.
    import(entry)
        .then(() => document.dispatchEvent(new Event('admin:mount-islands')))
        .catch(() => {
            mount.dataset.islandDefer = entry;
        });
};

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-palette-open]')) {
        open(event);
    }
});

document.addEventListener('keydown', (event) => {
    if (isShortcut(event)) {
        open(event);
    }
});

if (!/Mac|iPhone|iPad/.test(navigator.platform)) {
    document.querySelectorAll('kbd[data-shortcut-other]').forEach((kbd) => {
        kbd.textContent = kbd.dataset.shortcutOther;
    });
}
