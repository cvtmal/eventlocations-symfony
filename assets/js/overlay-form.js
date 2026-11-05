// Overlay form (#12, shared with #11): "Filter löschen" empties the form (checkboxes and radios unchecked, text and
// selects emptied, hidden inputs kept) and stays open, closing discards what was not applied. For every form[data-overlay-form] in a popover; the link is [data-overlay-clear]. live-count.js recounts.
// Self-contained: no imports, no globals.
(() => {
    document.querySelectorAll('form[data-overlay-form]').forEach((form) => {
        const overlay = form.closest('[popover]');
        if (!overlay) {
            return;
        }

        overlay.querySelector('[data-overlay-clear]')?.addEventListener('click', (event) => {
            event.preventDefault();
            [...form.elements].forEach((control) => {
                if (control.type === 'checkbox' || control.type === 'radio') {
                    control.checked = false;
                } else if (control.matches('input:not([type="hidden"]), select')) {
                    control.value = '';
                }
            });
            form.dispatchEvent(new Event('change'));
        });

        // Restores what the server rendered; a hidden field is never changed here.
        overlay.addEventListener('toggle', (event) => {
            if (event.newState === 'closed') {
                form.reset();
            }
        });
    });
})();
