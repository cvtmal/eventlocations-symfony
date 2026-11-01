import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test
// runs it as a script. jsdom has no layout, so every test stubs the values the script reads.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/carousel.js`, 'utf8');

function stub(element, values) {
    Object.entries(values).forEach(([name, value]) => {
        Object.defineProperty(element, name, { configurable: true, get: () => value });
    });
}

// `perView` slides of 300 px show at once, `gap` apart. `hidden` is a closed gallery: no layout at all.
function mount({ total, perView = 1, gap = 30, hidden = false }) {
    document.body.innerHTML = `
        <div data-carousel data-counter-format="{current} / {total}">
            <ul class="carousel__track">${'<li class="carousel__slide"></li>'.repeat(total)}</ul>
            <div data-carousel-controls hidden>
                <button data-carousel-prev></button>
                <span data-carousel-counter></span>
                <button data-carousel-next></button>
            </div>
        </div>`;

    const track = document.querySelector('.carousel__track');
    const step = hidden ? 0 : 300 + gap;
    const clientWidth = hidden ? 0 : perView * step - gap;
    const scrollWidth = hidden ? 0 : total * step - gap;

    track.querySelectorAll('.carousel__slide').forEach((slide, index) => stub(slide, { offsetLeft: index * step }));
    stub(track, { clientWidth, scrollWidth });
    track.scrollBy = vi.fn();
    new Function(SCRIPT)();

    return { track, clientWidth, scrollWidth, step };
}

const counter = () => document.querySelector('[data-carousel-counter]').textContent;
const prev = () => document.querySelector('[data-carousel-prev]');
const next = () => document.querySelector('[data-carousel-next]');

function scrollTo(track, left) {
    Object.defineProperty(track, 'scrollLeft', { configurable: true, writable: true, value: left });
    track.dispatchEvent(new Event('scroll'));

    return counter();
}

describe('carousel position (#39)', () => {
    beforeEach(() => {
        window.matchMedia = () => ({ matches: false });
        // Runs the frame at once; 0 leaves the script's `frame ||=` open for the next scroll.
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback();

            return 0;
        });
    });

    it('reads a one-up track slide by slide, as before', () => {
        const { track, step, scrollWidth, clientWidth } = mount({ total: 3, gap: 0 });

        expect(counter()).toBe('1 / 3');
        expect([prev().disabled, next().disabled]).toEqual([true, false]);

        expect(scrollTo(track, step)).toBe('2 / 3');
        expect([prev().disabled, next().disabled]).toEqual([false, false]);

        expect(scrollTo(track, scrollWidth - clientWidth)).toBe('3 / 3');
        expect([prev().disabled, next().disabled]).toEqual([false, true]);
    });

    it('takes a two-up track that stops short of the last slide to its end', () => {
        const { track, step, scrollWidth, clientWidth } = mount({ total: 3, perView: 2 });

        expect(scrollWidth - clientWidth).toBe(step);
        expect(counter()).toBe('1 / 3');
        expect(next().disabled).toBe(false);

        expect(scrollTo(track, step)).toBe('3 / 3');
        expect([prev().disabled, next().disabled]).toEqual([false, true]);
    });

    it('allows a pixel of rounding at the end of the track', () => {
        const { track, scrollWidth, clientWidth } = mount({ total: 3, perView: 2 });

        expect(scrollTo(track, scrollWidth - clientWidth - 1)).toBe('3 / 3');
        expect(next().disabled).toBe(true);
    });

    it('reads 12 slides three-up as 1, 4, 7, 12', () => {
        const { track, step, scrollWidth, clientWidth } = mount({ total: 12, perView: 3 });

        const read = [counter(), scrollTo(track, 3 * step), scrollTo(track, 6 * step), scrollTo(track, scrollWidth - clientWidth)];

        expect(read).toEqual(['1 / 12', '4 / 12', '7 / 12', '12 / 12']);
        expect(next().disabled).toBe(true);
    });

    it('keeps "next" enabled on a track with no layout, a closed gallery', () => {
        mount({ total: 3, hidden: true });

        expect(counter()).toBe('1 / 3');
        expect([prev().disabled, next().disabled]).toEqual([true, false]);
    });
});
