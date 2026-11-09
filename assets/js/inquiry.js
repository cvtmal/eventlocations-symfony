// Inquiry form and its outcome (DP-13, F13, F16 N4/N5). Self-contained: no imports, no globals.
(() => {
    // The confirmation lands mid-page, and a live region present at load is not announced: focusing its heading makes
    // a screen reader read it. The redirect's fragment is that heading, which Chrome focuses by itself (a fragment that
    // cannot take the focus clears it after this script); this covers a browser that only scrolls to it. `autofocus`
    // would not do: a URL fragment suppresses it.
    document.querySelector('[data-inquiry-focus]')?.focus({preventScroll: true});

    // The message is prefilled with a template. Keyboard focus puts the caret at 0, so typed text would run into the
    // greeting; on the first focus of an untouched template it goes to the end instead. A pointer keeps the caret
    // where it was put; a swipe that starts on the field (pointercancel) focuses nothing.
    document.querySelectorAll('textarea[data-inquiry-message]').forEach((field) => {
        let pointer = false;
        let done = false;
        field.addEventListener('pointerdown', () => {
            pointer = true;
        });
        field.addEventListener('pointercancel', () => {
            pointer = false;
        });
        field.addEventListener('focus', () => {
            if (done) {
                return;
            }
            done = true;
            if (pointer || field.value !== field.defaultValue || field.selectionEnd !== 0) {
                return;
            }
            const end = field.value.length;
            field.setSelectionRange(end, end);
            field.scrollTop = field.scrollHeight;
        });
    });
})();
