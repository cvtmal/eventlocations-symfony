// Places an open dropdown under its trigger, or above it at the viewport's bottom edge. `toggle` does not
// bubble, so this listens while capturing. CSSOM writes are allowed under `style-src 'self'`.
const GAP = 4;
const EDGE = 8;

document.addEventListener('toggle', (event) => {
    const menu = event.target;
    if (!(menu instanceof HTMLElement) || !menu.classList.contains('dropdown') || event.newState !== 'open') {
        return;
    }

    const trigger = document.querySelector(`[popovertarget="${menu.id}"]`);
    if (!trigger) {
        return;
    }

    const anchor = trigger.getBoundingClientRect();
    const box = menu.getBoundingClientRect();
    const fitsBelow = anchor.bottom + GAP + box.height <= window.innerHeight;
    const top = fitsBelow ? anchor.bottom + GAP : Math.max(EDGE, anchor.top - GAP - box.height);
    const left = Math.max(EDGE, Math.min(anchor.right - box.width, window.innerWidth - box.width - EDGE));

    menu.style.top = `${top}px`;
    menu.style.left = `${left}px`;
}, true);
