// Back-office toasts: the dismiss button closes one, success toasts hide after 6 s unless the
// pointer or focus is on them. Without JS they stay until the next page.
const HIDE_AFTER_MS = 6000;

document.querySelectorAll('[data-toast]').forEach((toast) => {
    const remove = () => toast.remove();
    toast.querySelector('[data-toast-dismiss]')?.addEventListener('click', remove);

    if (!toast.matches('[data-toast-autohide]')) {
        return;
    }

    let timer = setTimeout(remove, HIDE_AFTER_MS);
    const pause = () => clearTimeout(timer);
    const resume = () => {
        timer = setTimeout(remove, HIDE_AFTER_MS);
    };

    toast.addEventListener('pointerenter', pause);
    toast.addEventListener('pointerleave', resume);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', resume);
});
