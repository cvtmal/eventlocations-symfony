// Overlay (#1): moves focus into a [data-overlay] popover when it opens and keeps Tab inside it.
// Opening, closing, Esc and focus return are native popover behaviour. Self-contained: no imports, no globals.
(() => {
    const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

    const focusables = (overlay) => [...overlay.querySelectorAll(FOCUSABLE)]
        .filter((el) => el.checkVisibility?.() ?? true);

    document.querySelectorAll('[data-overlay][popover]').forEach((overlay) => {
        overlay.addEventListener('toggle', (event) => {
            if (event.newState === 'open') {
                const first = focusables(overlay)[0];
                if (first) {
                    first.focus();
                }
            }
        });

        overlay.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab' || !overlay.matches(':popover-open')) {
                return;
            }
            const items = focusables(overlay);
            if (items.length === 0) {
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    });
})();
