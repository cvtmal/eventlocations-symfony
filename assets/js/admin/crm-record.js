// Keeps a CRM record's head in step with its inline editor (`crm:property-saved` from the
// `record-properties` island): an element `[data-crm-follow="<field>"]` takes the saved display text, a
// link also the value as its address, a pill also the tone of `data-crm-tones` ({code: tone}). An emptied
// field hides it. Without JS the page shows the saved values on the next load. `[data-crm-refresh="<field>"]`
// marks what the event cannot rebuild, such as the Firma card's initials and stage: that element is replaced
// from a fresh copy of the page, never by a reload, which would lose what is typed in other rows. If the
// copy fails the element stays as it was until the next load; the saved row itself is right.
let pendingRefresh = null;

async function refresh(selector) {
    pendingRefresh?.abort();
    const controller = new AbortController();
    pendingRefresh = controller;

    try {
        const response = await fetch(window.location.href, { redirect: 'manual', signal: controller.signal });
        if (!response.ok) {
            return;
        }
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = page.querySelector(selector);
        if (fresh && !controller.signal.aborted) {
            document.querySelector(selector)?.replaceWith(fresh);
        }
    } catch {
        // A newer save took over, or the copy failed: see above.
    }
}

document.addEventListener('crm:property-saved', (event) => {
    const { name, value, display } = event.detail ?? {};
    if (typeof name !== 'string') {
        return;
    }
    const refreshed = `[data-crm-refresh="${CSS.escape(name)}"]`;
    if (document.querySelector(refreshed)) {
        refresh(refreshed);
    }
    for (const target of document.querySelectorAll(`[data-crm-follow="${CSS.escape(name)}"]`)) {
        target.textContent = display ?? '';
        target.hidden = !display;
        if (target instanceof HTMLAnchorElement && value) {
            target.href = value;
        }
        if (target.dataset.crmTones) {
            const tones = JSON.parse(target.dataset.crmTones);
            target.className = `pill pill--${tones[value] ?? 'grey'}`;
        }
    }
});
