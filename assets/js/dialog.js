// Dialog (#1): a fallback for invoker commands (commandfor/command) where the browser lacks them,
// and light-dismiss on a backdrop click. Self-contained: no imports, no globals.
(() => {
    if (!('command' in HTMLButtonElement.prototype)) {
        document.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('[commandfor]') : null;
            if (!button) {
                return;
            }
            const dialog = document.getElementById(button.getAttribute('commandfor'));
            if (!(dialog instanceof HTMLDialogElement)) {
                return;
            }
            const command = button.getAttribute('command');
            if (command === 'show-modal') {
                dialog.showModal();
            } else if (command === 'close') {
                dialog.close();
            }
        });
    }

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLDialogElement) || !target.matches('[data-dialog]') || !target.open) {
            return;
        }
        // The dialog itself is the target for clicks on its padding too; close only outside its box.
        const box = target.getBoundingClientRect();
        const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
        if (!inside) {
            target.close();
        }
    });

    // `close` does not bubble, hence capture. The browser returns focus to the opener, but an opener inside a popover
    // is hidden by then and focus falls to <body>: hand it to the popover's trigger instead (APG dialog pattern).
    document.addEventListener('close', (event) => {
        const dialog = event.target;
        if (!(dialog instanceof HTMLDialogElement) || !dialog.id || (document.activeElement !== document.body && !dialog.contains(document.activeElement))) {
            return;
        }
        const opener = [...document.querySelectorAll('[commandfor]')].find((el) => el.getAttribute('commandfor') === dialog.id);
        const popover = opener?.closest('[popover]');
        const trigger = popover?.id ? [...document.querySelectorAll('[popovertarget]')].find((el) => el.getAttribute('popovertarget') === popover.id) : null;
        (trigger ?? opener)?.focus();
    }, true);
})();
