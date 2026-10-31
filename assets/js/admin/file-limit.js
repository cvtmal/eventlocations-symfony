// Stops a file above its form's limit before the form is sent (#25): a body above nginx's limit answers
// with a raw 413 page and the typed fields are gone. The limit and the message come from the server
// (`data-max-bytes`, `data-max-message`); without JavaScript the 413 stands.
document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('input[type="file"][data-max-bytes]')) {
        return;
    }

    const id = `${input.id}_limit`;
    const describedBy = (input.getAttribute('aria-describedby') ?? '').split(' ').filter((entry) => entry && entry !== id);
    document.getElementById(id)?.remove();
    input.removeAttribute('aria-invalid');

    if ((input.files?.[0]?.size ?? 0) > Number(input.dataset.maxBytes)) {
        const message = document.createElement('span');
        message.id = id;
        message.className = 'field__error';
        message.setAttribute('role', 'alert');
        message.textContent = input.dataset.maxMessage;
        input.closest('.field')?.append(message);
        describedBy.push(id);
        input.setAttribute('aria-invalid', 'true');
        input.value = '';
    }

    if (describedBy.length > 0) {
        input.setAttribute('aria-describedby', describedBy.join(' '));
    } else {
        input.removeAttribute('aria-describedby');
    }
});
