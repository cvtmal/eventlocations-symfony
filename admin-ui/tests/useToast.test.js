import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useToast } from '../src/composables/useToast.js';

describe('useToast', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '<div class="toast-region" role="status"></div>';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('appends the markup of _flash.html.twig to the region', () => {
        useToast({ dismissLabel: 'Schliessen' }).show('Gespeichert.');

        const toast = document.querySelector('.toast-region > .toast.toast--success[data-toast][data-toast-autohide]');
        expect(toast).not.toBeNull();
        expect(toast.querySelector('svg.icon[aria-hidden="true"] use').getAttribute('href')).toMatch(/#check$/);
        expect(toast.querySelector('.toast__message').textContent).toBe('Gespeichert.');
        expect(toast.querySelector('button.toast__dismiss[data-toast-dismiss]').getAttribute('aria-label')).toBe('Schliessen');
    });

    it('prints the message as text', () => {
        useToast({ dismissLabel: 'x' }).show('<b>fett</b>');

        expect(document.querySelector('.toast__message b')).toBeNull();
    });

    it('hides a success after 6 s', () => {
        useToast({ dismissLabel: 'x' }).show('Gespeichert.');

        vi.advanceTimersByTime(5999);
        expect(document.querySelector('.toast')).not.toBeNull();

        vi.advanceTimersByTime(1);
        expect(document.querySelector('.toast')).toBeNull();
    });

    it('waits while the pointer is on it', () => {
        const toast = useToast({ dismissLabel: 'x' }).show('Gespeichert.');

        toast.dispatchEvent(new Event('pointerenter'));
        vi.advanceTimersByTime(20000);

        expect(document.querySelector('.toast')).not.toBeNull();
    });

    it('keeps an error until it is dismissed', () => {
        useToast({ dismissLabel: 'x' }).show('Fehlgeschlagen.', 'error');

        vi.advanceTimersByTime(60000);
        const toast = document.querySelector('.toast.toast--error');
        expect(toast.querySelector('use').getAttribute('href')).toMatch(/#x$/);

        toast.querySelector('button').click();
        expect(document.querySelector('.toast')).toBeNull();
    });
});
