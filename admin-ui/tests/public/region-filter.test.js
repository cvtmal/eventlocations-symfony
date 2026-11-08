import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it } from 'vitest';

// A public-site script, not an admin island: it has no exports and runs once per page, so each test
// runs it as a script over a hand-built region form. Only the #17 submit handler is under test here;
// the overlay itself (#10) is checked in a browser.
const SCRIPT = readFileSync(`${import.meta.dirname}/../../../assets/js/region-filter.js`, 'utf8');
const ORIGIN = location.origin;
const BASE = `${ORIGIN}/eventlokale`;

function page(landing = { zh: '/eventlokale/zuerich' }) {
    document.body.innerHTML = `
        <div popover>
            <form data-region-filter action="/eventlokale" method="get" ${landing ? `data-landing='${JSON.stringify(landing)}'` : ''}
                data-places-url="/_search/places" data-no-places="—" data-radius-zero="nur" data-radius-other="%n km">
                <div data-region-tabs hidden>
                    <button type="button" role="tab" data-tab="canton"></button>
                    <button type="button" role="tab" data-tab="place"></button>
                </div>
                <div data-region-js hidden>
                    <div data-panel="canton">
                        <input id="filter-region-canton" aria-controls="filter-region-canton-list">
                        <ul id="filter-region-canton-list">
                            <li role="option" data-value="zh">Zürich</li>
                            <li role="option" data-value="be">Bern</li>
                        </ul>
                        <div data-region-map>
                            <svg><g id="cantons"><path id="ch-zh"></path><path id="ch-be"></path></g></svg>
                            <div data-region-tooltip hidden></div>
                        </div>
                    </div>
                    <div data-panel="place">
                        <input id="filter-region-place" name="place_text" aria-controls="filter-region-place-list">
                        <ul id="filter-region-place-list"></ul>
                        <input id="filter-region-radius" type="range" name="radius" value="5" data-default="5">
                        <output hidden></output>
                    </div>
                </div>
                <input type="hidden" name="canton" data-region-value="canton" value="">
                <input type="hidden" name="place" data-region-value="place" value="">
            </form>
            <button data-region-clear></button>
        </div>`;
    new Function(SCRIPT)();

    return document.querySelector('form');
}

const canton = () => document.querySelector('[data-region-value="canton"]');
const pick = (code) => document.querySelector(`#ch-${code}`).dispatchEvent(new MouseEvent('click', { bubbles: true }));
const tab = (name) => document.querySelector(`[data-tab="${name}"]`).click();
const submit = (form) => form.dispatchEvent(new Event('submit', { cancelable: true }));

describe('region filter submit (#17)', () => {
    beforeEach(() => {
        window.matchMedia ??= () => ({ matches: false });
    });

    it('sends a canton with a landing page to its path, without the canton parameter', () => {
        const form = page();
        pick('zh');
        submit(form);

        expect(form.action).toBe(`${ORIGIN}/eventlokale/zuerich`);
        expect(canton().disabled).toBe(true);
    });

    it('keeps the listing and the parameter for a canton without a landing page', () => {
        const form = page();
        pick('be');
        submit(form);

        expect(form.action).toBe(BASE);
        expect(canton().disabled).toBe(false);
        expect(canton().value).toBe('be');
    });

    it('keeps the listing and disables the canton on the Ort tab', () => {
        const form = page();
        pick('zh');
        tab('place');
        submit(form);

        expect(form.action).toBe(BASE);
        expect(canton().disabled).toBe(true);
    });

    it('re-enables the canton on a later submit to a canton without a landing page', () => {
        const form = page();
        pick('zh');
        submit(form);
        pick('be');
        submit(form);

        expect(form.action).toBe(BASE);
        expect(canton().disabled).toBe(false);
    });

    it('re-enables the canton when the page comes back from the bfcache', () => {
        const form = page();
        pick('zh');
        submit(form);
        window.dispatchEvent(new Event('pageshow'));

        expect(canton().disabled).toBe(false);

        tab('place');
        window.dispatchEvent(new Event('pageshow'));

        expect(canton().disabled).toBe(true);
    });

    it('works without a landing map', () => {
        const form = page(null);
        pick('zh');
        submit(form);

        expect(form.action).toBe(BASE);
        expect(canton().disabled).toBe(false);
    });
});
