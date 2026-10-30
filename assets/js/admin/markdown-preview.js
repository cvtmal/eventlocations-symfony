// Fills the Vorschau tab of a Markdown field. It cannot import admin-ui's api.js, so it repeats its rules:
// only a redirect, a 401 or a successful non-JSON answer means the session is gone; a 413 or a 500 does not.
const running = new WeakMap();

const isJson = (response) => (response.headers.get('Content-Type') ?? '').includes('json');

const isSessionGone = (response) => response.type === 'opaqueredirect'
    || (response.status >= 300 && response.status < 400)
    || response.status === 401
    || (response.ok && !isJson(response));

const fetchPreview = (root, text, signal) => fetch(root.dataset.markdownUrl, {
    method: 'POST',
    redirect: 'manual',
    signal,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify({ text }),
});

document.addEventListener('click', async (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[data-markdown-preview]') : null;
    const root = tab?.closest('[data-markdown]');
    if (!root) {
        return;
    }

    const output = root.querySelector('[data-markdown-output]');
    const status = root.querySelector('[data-markdown-status]');
    const show = (message) => {
        status.textContent = message;
        status.hidden = false;
        output.replaceChildren();
    };

    running.get(root)?.abort();
    const controller = new AbortController();
    running.set(root, controller);

    try {
        const response = await fetchPreview(root, root.querySelector('textarea').value, controller.signal);

        if (isSessionGone(response)) {
            show(root.dataset.sessionGone);
        } else if (!response.ok) {
            show(root.dataset.error);
        } else {
            const { html } = await response.json();
            status.hidden = true;
            output.innerHTML = html;
        }
    } catch (error) {
        if (error.name !== 'AbortError') {
            show(root.dataset.error);
        }
    }
});
