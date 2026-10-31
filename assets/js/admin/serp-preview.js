// Keeps a landing page editor's search-result preview current (#26): a typed title or description shows as
// typed (trimmed, as it is stored), an emptied field shows its placeholder, the text the page has without one.
document.addEventListener('input', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || !field.dataset.serpSource) {
        return;
    }

    const target = document.querySelector(
        `[data-serp-target="${field.dataset.serpSource}"][data-serp-locale="${field.dataset.serpLocale}"]`,
    );
    if (target) {
        target.textContent = field.value.trim() || field.placeholder;
    }
});
