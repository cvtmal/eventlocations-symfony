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
})();
