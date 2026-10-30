// Keeps a field's "148 / 200" current. It counts what the server counts: browsers submit \r\n, Symfony
// trims, and the Length constraint uses mb_strlen (code points, not UTF-16 units).
document.addEventListener('input', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLElement) || !field.matches('[data-counter-max]')) {
        return;
    }

    const counter = document.querySelector(`[data-counter-for="${field.id}"]`);
    if (!counter) {
        return;
    }

    const max = Number(field.dataset.counterMax);
    const length = [...field.value.trim().replace(/\n/g, '\r\n')].length;

    counter.textContent = `${length} / ${max}`;
    counter.classList.toggle('field__counter--over', length > max);
});
