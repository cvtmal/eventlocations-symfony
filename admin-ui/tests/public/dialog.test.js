import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

// jsdom has no `command`, so dialog.js takes the fallback branch older iPhones take. It listens on
// document: imported once, as a page does.
beforeAll(async () => {
    await import('../../../assets/js/dialog.js');
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.replaceChildren();
});

describe('dialog.js invoker fallback (#41)', () => {
    it('opens the dialog a show-modal button names', () => {
        document.body.innerHTML = `
            <button commandfor="confirm" command="show-modal">Öffnen</button>
            <dialog id="confirm"></dialog>`;
        const showModal = vi.spyOn(document.getElementById('confirm'), 'showModal');

        document.querySelector('button').click();

        expect(showModal).toHaveBeenCalledOnce();
    });

    it('closes the dialog a close button names', () => {
        document.body.innerHTML = `
            <dialog id="confirm" open><button commandfor="confirm" command="close">Schliessen</button></dialog>`;
        const close = vi.spyOn(document.getElementById('confirm'), 'close');

        document.querySelector('button').click();

        expect(close).toHaveBeenCalledOnce();
    });

    it('does nothing when the button names something that is no dialog', () => {
        document.body.innerHTML = `
            <button commandfor="confirm" command="show-modal">Öffnen</button>
            <div id="confirm"></div>`;
        const target = document.getElementById('confirm');
        target.showModal = vi.fn();

        document.querySelector('button').click();

        expect(target.showModal).not.toHaveBeenCalled();
    });
});
