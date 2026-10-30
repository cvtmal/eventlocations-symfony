// Warns before the page is left with typed input in a card that is not being saved (D28). Each editor card is its own
// form, and a save reloads the page, so the other cards' input would otherwise vanish without a word. A form opts in
// with `data-unsaved-guard`; the reload after a save is a fresh page, so the state starts empty again.
const dirty = new Set();
let submitted = null;

function markDirty(event) {
    const field = event.target;
    const form = field instanceof Element ? (field.form ?? field.closest('form')) : null;
    if (form instanceof HTMLFormElement && form.matches('[data-unsaved-guard]')) {
        dirty.add(form);
    }
}

document.addEventListener('input', markDirty);
document.addEventListener('change', markDirty);

document.addEventListener('submit', (event) => {
    submitted = event.target;
});

window.addEventListener('beforeunload', (event) => {
    const unsaved = [...dirty].some((form) => form !== submitted && form.isConnected);
    // A cancelled leave keeps the page: the next attempt is judged on its own.
    submitted = null;
    if (unsaved) {
        event.preventDefault();
        // Chromium before 119 and Safari still need the legacy return value.
        event.returnValue = '';
    }
});
