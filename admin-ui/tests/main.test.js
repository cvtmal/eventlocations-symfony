import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../src/islands.js', () => ({ default: { hello: vi.fn(async () => ({ default: { render: () => null } })) } }));

describe('main', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('mounts on load and again on the event, which a deferred island needs', async () => {
        const { default: islands } = await import('../src/islands.js');
        document.body.innerHTML = '<div data-island="hello" data-island-defer="/main.js"></div>';

        await import('../src/main.js');
        await vi.waitFor(() => expect(document.querySelector('[data-island]').dataset.islandDefer).toBeDefined());
        expect(islands.hello).not.toHaveBeenCalled();

        document.querySelector('[data-island]').removeAttribute('data-island-defer');
        document.dispatchEvent(new Event('admin:mount-islands'));

        await vi.waitFor(() => expect(islands.hello).toHaveBeenCalledTimes(1));
    });
});
