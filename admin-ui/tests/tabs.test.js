import { afterEach, describe, expect, it, vi } from 'vitest';

function recordTabs(active = 2) {
    document.body.innerHTML = `<nav class="tabs tabs--record">${[0, 1, 2].map((index) => `<a class="tab${index === active ? ' tab--active' : ''}" href="#${index}">Tab</a>`).join('')}</nav>`;

    const nav = document.querySelector('nav');
    const rects = [{ left: 16, right: 100 }, { left: 128, right: 300 }, { left: 328, right: 435 }];

    nav.getBoundingClientRect = () => ({ left: 16, right: 374 });
    [...nav.children].forEach((tab, index) => {
        tab.getBoundingClientRect = () => ({
            left: rects[index].left - nav.scrollLeft,
            right: rects[index].right - nav.scrollLeft,
        });
    });

    return nav;
}

async function load() {
    vi.resetModules();
    await import('../../assets/js/admin/tabs.js');
}

describe('tabs.js, record tabs', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('brings the current tab into view on load, without moving the page', async () => {
        const nav = recordTabs();
        const scrollIntoView = vi.fn();

        Element.prototype.scrollIntoView = scrollIntoView;
        await load();
        delete Element.prototype.scrollIntoView;

        expect(nav.scrollLeft).toBe(61);
        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('leaves tabs that fit alone', async () => {
        const nav = recordTabs(1);

        await load();

        expect(nav.scrollLeft).toBe(0);
    });

    it('brings a tab in that keyboard focus reaches, in both directions', async () => {
        const nav = recordTabs(0);

        await load();
        nav.children[2].focus();

        expect(nav.scrollLeft).toBe(61);

        nav.children[0].focus();

        expect(nav.scrollLeft).toBe(0);
    });
});
