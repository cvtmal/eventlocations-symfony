import { spriteUrl } from '../icons.js';

// Toasts from an island, with the markup of templates/admin/layout/_flash.html.twig in `.toast-region`.
// toast.js runs once on load, so the dismiss button and the 6 s hide are wired here.
const HIDE_AFTER_MS = 6000;
const SVG = 'http://www.w3.org/2000/svg';

function icon(name) {
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');

    const use = document.createElementNS(SVG, 'use');
    use.setAttribute('href', `${spriteUrl()}#${name}`);
    svg.append(use);

    return svg;
}

function autohide(toast) {
    const remove = () => toast.remove();
    let timer = setTimeout(remove, HIDE_AFTER_MS);
    const pause = () => clearTimeout(timer);
    const resume = () => {
        timer = setTimeout(remove, HIDE_AFTER_MS);
    };

    toast.addEventListener('pointerenter', pause);
    toast.addEventListener('pointerleave', resume);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', resume);
}

export function useToast({ dismissLabel }) {
    function show(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.dataset.toast = '';

        const text = document.createElement('span');
        text.className = 'toast__message';
        text.textContent = message;

        const dismiss = document.createElement('button');
        dismiss.className = 'toast__dismiss';
        dismiss.type = 'button';
        dismiss.dataset.toastDismiss = '';
        dismiss.setAttribute('aria-label', dismissLabel);
        dismiss.append(icon('x'));
        dismiss.addEventListener('click', () => toast.remove());

        toast.append(icon(type === 'success' ? 'check' : 'x'), text, dismiss);
        if (type === 'success') {
            toast.dataset.toastAutohide = '';
            autohide(toast);
        }

        document.querySelector('.toast-region')?.append(toast);

        return toast;
    }

    return { show };
}
