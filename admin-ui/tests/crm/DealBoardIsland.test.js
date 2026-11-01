import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DealBoardIsland from '../../src/islands/DealBoardIsland.vue';

const labels = {
    openOne: 'Offen: CHF %sum · 1 Deal',
    openOther: 'Offen: CHF %sum · %n Deals',
    sum: 'CHF %sum',
    more: '+ %n weitere',
    next: 'Weiter →',
    nextTo: '%deal nach %stage verschieben',
    moveOf: '%deal verschieben nach',
    moved: '%deal ist jetzt in %stage.',
    movedBack: '%deal bleibt in %stage.',
    closesOn: 'Abschluss %date',
    wonOn: 'Gewonnen %date',
    lostBecause: 'Verloren: %reason',
    owner: 'Zuständig: %name',
    lostTitle: 'Deal verloren',
    lostText: '%deal wandert nach Verloren. Warum?',
    lostReason: 'Grund',
    lostChoose: 'Bitte wählen',
    lostRequired: 'Bitte einen Grund wählen.',
    lostConfirm: 'Verschieben',
    lostCancel: 'Abbrechen',
    lostClose: 'Schliessen',
    tierTitle: 'Tier auf Location anwenden',
    tierConfirm: 'Tier anwenden',
    tierCancel: 'Nicht jetzt',
    tierClose: 'Schliessen',
    stale: 'Dieser Deal wurde inzwischen verschoben.',
    notFound: 'Dieser Deal existiert nicht mehr.',
    reload: 'Seite neu laden',
    sessionGone: 'Sitzung abgelaufen.',
    forbidden: 'Sitzung abgelaufen.',
    error: 'Verschieben nicht möglich.',
};

function card(id, overrides = {}) {
    return {
        id,
        name: `Deal ${id}`,
        company: 'Seeblick Events',
        href: `/admin/crm/deals/${id}`,
        stageUrl: `/admin/api/crm/deals/${id}/stage`,
        tier: null,
        amountRappen: 100000,
        expectedCloseOn: '2026-11-15',
        closedOn: null,
        lostReason: null,
        ownerName: 'Andrea Keller',
        ownerInitials: 'AK',
        ...overrides,
    };
}

function column(code, label, cards = [], overrides = {}) {
    return {
        code,
        label,
        isWon: false,
        isLost: false,
        count: cards.length,
        sumRappen: cards.reduce((sum, { amountRappen }) => sum + (amountRappen ?? 0), 0),
        moreHref: `/admin/crm/deals?ansicht=liste&stage=${code}`,
        cards,
        ...overrides,
    };
}

function board(overrides = {}) {
    return [
        column('new', 'Neu', [card('a'), card('b', { amountRappen: null })], overrides.new),
        column('offer', 'Angebot', [], overrides.offer),
        column('won', 'Gewonnen', [card('w', { closedOn: '2026-10-21' })], { isWon: true, ...overrides.won }),
        column('lost', 'Verloren', [card('l', { lostReason: 'budget' })], { isLost: true, ...overrides.lost }),
    ];
}

const lostReasons = [{ value: 'budget', label: 'Budget' }, { value: 'other', label: 'Anderer Grund' }];

function json(body, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function island(overrides) {
    return mount(DealBoardIsland, { props: { columns: board(overrides), lostReasons, labels }, attachTo: document.body });
}

const columnOf = (wrapper, code) => wrapper.get(`[data-column="${code}"]`);
const cardOf = (wrapper, id) => wrapper.get(`[data-deal="${id}"]`);
const cardsIn = (wrapper, code) => columnOf(wrapper, code).findAll('[data-deal]').map((element) => element.attributes('data-deal'));
const status = (wrapper) => wrapper.get('[role="status"]').text();
const next = (wrapper, id) => cardOf(wrapper, id).get('[data-deal-focus]');

async function drag(wrapper, id, target) {
    const setData = vi.fn();
    await cardOf(wrapper, id).trigger('dragstart', { dataTransfer: { setData } });
    await columnOf(wrapper, target).trigger('dragenter');
    await columnOf(wrapper, target).trigger('drop');

    return setData;
}

function stubFetch(answer) {
    const fetch = vi.fn(answer);
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

const sent = (fetch, call = 0) => JSON.parse(fetch.mock.calls[call][1].body);

describe('DealBoardIsland', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('shows the columns with their server totals and the cards', () => {
        const wrapper = island({ new: { count: 150, sumRappen: 123450 } });

        expect(columnOf(wrapper, 'new').get('h2').text()).toBe('Neu');
        expect(columnOf(wrapper, 'new').get('.pill').text()).toBe('150');
        expect(columnOf(wrapper, 'new').get('.deal-column__sum').text()).toBe("CHF 1'234.50");
        expect(cardOf(wrapper, 'a').text()).toContain('Seeblick Events');
        expect(cardOf(wrapper, 'a').text()).toContain("CHF 1'000");
        expect(cardOf(wrapper, 'a').text()).toContain('Abschluss 15.11.2026');
        expect(cardOf(wrapper, 'w').text()).toContain('Gewonnen 21.10.2026');
        expect(cardOf(wrapper, 'l').text()).toContain('Verloren: Budget');
    });

    it('links a capped column to the list', () => {
        const wrapper = island({ new: { count: 102 } });

        expect(columnOf(wrapper, 'new').get('.deal-column__more').text()).toBe('+ 100 weitere');
        expect(columnOf(wrapper, 'new').get('.deal-column__more').attributes('href')).toBe('/admin/crm/deals?ansicht=liste&stage=new');
        expect(columnOf(wrapper, 'offer').find('.deal-column__more').exists()).toBe(false);
    });

    it('moves a dragged card and tells the browser what is dragged', async () => {
        const fetch = stubFetch(async () => json({ stage: 'offer', closedOn: null }));
        const wrapper = island();

        const setData = await drag(wrapper, 'a', 'offer');
        await flushPromises();

        expect(setData).toHaveBeenCalledWith('text/plain', 'a');
        expect(cardsIn(wrapper, 'offer')).toEqual(['a']);
        expect(cardsIn(wrapper, 'new')).toEqual(['b']);
        expect(fetch.mock.calls[0][0]).toBe('/admin/api/crm/deals/a/stage');
        expect(fetch.mock.calls[0][1].method).toBe('PATCH');
        expect(sent(fetch)).toEqual({ stage: 'offer', expected: 'new', lostReason: null });
    });

    it('sends nothing for a drop on its own column', async () => {
        const fetch = stubFetch(async () => json({}));
        const wrapper = island();

        await drag(wrapper, 'a', 'new');
        await flushPromises();

        expect(fetch).not.toHaveBeenCalled();
        expect(cardsIn(wrapper, 'new')).toEqual(['a', 'b']);
    });

    it('ignores a drop that is no card of the board', async () => {
        const fetch = stubFetch(async () => json({}));
        const wrapper = island();

        await columnOf(wrapper, 'offer').trigger('drop');

        expect(fetch).not.toHaveBeenCalled();
    });

    it('moves a card a step with Weiter and keeps the focus on it', async () => {
        stubFetch(async () => json({ stage: 'offer', closedOn: null }));
        const wrapper = island();

        expect(next(wrapper, 'a').attributes('aria-label')).toBe('Deal a nach Angebot verschieben');
        await next(wrapper, 'a').trigger('click');
        await flushPromises();

        expect(cardsIn(wrapper, 'offer')).toEqual(['a']);
        expect(document.activeElement).toBe(next(wrapper, 'a').element);
        expect(next(wrapper, 'a').attributes('aria-label')).toBe('Deal a nach Gewonnen verschieben');
    });

    it('offers Weiter up to won, never to lost and never from won or lost', () => {
        const wrapper = island();

        expect(cardOf(wrapper, 'w').text()).not.toContain('Weiter');
        expect(cardOf(wrapper, 'l').text()).not.toContain('Weiter');
        expect(cardOf(wrapper, 'a').text()).toContain('Weiter');
    });

    it('moves a card through the menu, which is no role=menu', async () => {
        const fetch = stubFetch(async () => json({ stage: 'won', closedOn: '2026-11-01' }));
        const wrapper = island();

        const menu = cardOf(wrapper, 'a').get('.dropdown');
        expect(menu.attributes('popover')).toBeDefined();
        expect(menu.attributes('role')).toBeUndefined();
        expect(cardOf(wrapper, 'a').get('[popovertarget]').attributes('popovertarget')).toBe(menu.attributes('id'));
        expect(menu.findAll('[data-deal-target]').map((item) => item.text())).toEqual(['Angebot', 'Gewonnen', 'Verloren']);

        await menu.get('[data-deal-target="won"]').trigger('click');
        await flushPromises();

        expect(cardsIn(wrapper, 'won')).toEqual(['a', 'w']);
        expect(sent(fetch).stage).toBe('won');
        expect(cardOf(wrapper, 'a').text()).toContain('Gewonnen 01.11.2026');
        expect(document.activeElement).toBe(cardOf(wrapper, 'a').get('[data-deal-focus]').element);
    });

    it('asks for the reason before it moves a card to lost', async () => {
        const fetch = stubFetch(async () => json({ stage: 'lost', closedOn: '2026-11-01' }));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="lost"]').trigger('click');

        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.get('dialog').attributes('open')).toBeDefined();
        expect(wrapper.get('dialog').text()).toContain('Deal a wandert nach Verloren.');
        expect(cardsIn(wrapper, 'new')).toEqual(['a', 'b']);

        await wrapper.get('#deal-lost-form').trigger('submit');
        expect(wrapper.get('#deal-lost-error').text()).toBe('Bitte einen Grund wählen.');
        expect(fetch).not.toHaveBeenCalled();

        await wrapper.get('#deal-lost-reason').setValue('budget');
        await wrapper.get('#deal-lost-form').trigger('submit');
        await flushPromises();

        expect(sent(fetch)).toEqual({ stage: 'lost', expected: 'new', lostReason: 'budget' });
        expect(cardsIn(wrapper, 'lost')).toEqual(['a', 'l']);
        expect(cardOf(wrapper, 'a').text()).toContain('Verloren: Budget');
        expect(wrapper.get('dialog').attributes('open')).toBeUndefined();
    });

    it('asks for the reason on a drag to lost too, and a cancel leaves the card', async () => {
        const fetch = stubFetch(async () => json({}));
        const wrapper = island();

        await drag(wrapper, 'a', 'lost');
        expect(wrapper.get('dialog').attributes('open')).toBeDefined();

        await wrapper.get('.modal__footer .btn--ghost').trigger('click');
        await flushPromises();

        expect(wrapper.get('dialog').attributes('open')).toBeUndefined();
        expect(cardsIn(wrapper, 'new')).toEqual(['a', 'b']);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('sends the prompt from its button, which sits outside the form', async () => {
        const fetch = stubFetch(async () => json({ stage: 'lost', closedOn: '2026-11-01' }));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="lost"]').trigger('click');
        await wrapper.get('#deal-lost-reason').setValue('budget');

        const submit = wrapper.get('#deal-lost button[type="submit"]');
        expect(submit.attributes('form')).toBe('deal-lost-form');
        await submit.trigger('click');
        await flushPromises();

        expect(sent(fetch)).toEqual({ stage: 'lost', expected: 'new', lostReason: 'budget' });
    });

    it('opens the prompt on the reason and goes back to it after a refusal', async () => {
        stubFetch(async () => json({}));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="lost"]').trigger('click');
        const reason = wrapper.get('#deal-lost-reason');
        expect(reason.attributes('autofocus')).toBeDefined();

        await wrapper.get('#deal-lost-form').trigger('submit');
        await flushPromises();

        expect(document.activeElement).toBe(reason.element);
        expect(reason.attributes('aria-describedby')).toBe('deal-lost-error');
    });

    it('drops the prompt\'s error once a reason is picked', async () => {
        stubFetch(async () => json({}));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="lost"]').trigger('click');
        await wrapper.get('#deal-lost-form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('#deal-lost-error').exists()).toBe(true);

        await wrapper.get('#deal-lost-reason').setValue('budget');

        expect(wrapper.find('#deal-lost-error').exists()).toBe(false);
        expect(wrapper.get('#deal-lost-reason').attributes('aria-invalid')).toBeUndefined();
    });

    it('moves a won deal back to an open stage and forgets the close', async () => {
        const fetch = stubFetch(async () => json({ stage: 'offer', closedOn: null }));
        const wrapper = island();

        await cardOf(wrapper, 'w').get('[data-deal-target="offer"]').trigger('click');
        await flushPromises();

        expect(sent(fetch)).toEqual({ stage: 'offer', expected: 'won', lostReason: null });
        expect(cardsIn(wrapper, 'offer')).toEqual(['w']);
        expect(cardOf(wrapper, 'w').text()).not.toContain('Gewonnen 21.10.2026');
        expect(cardOf(wrapper, 'w').text()).toContain('Abschluss 15.11.2026');
        expect(columnOf(wrapper, 'won').get('.pill').text()).toBe('0');
    });

    it('moves a lost deal back to an open stage without asking and forgets the reason', async () => {
        const fetch = stubFetch(async () => json({ stage: 'new', closedOn: null }));
        const wrapper = island();

        await cardOf(wrapper, 'l').get('[data-deal-target="new"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('#deal-lost').attributes('open')).toBeUndefined();
        expect(sent(fetch)).toEqual({ stage: 'new', expected: 'lost', lostReason: null });
        expect(cardsIn(wrapper, 'new')).toEqual(['l', 'a', 'b']);
        expect(cardOf(wrapper, 'l').text()).not.toContain('Verloren: Budget');
        expect(cardOf(wrapper, 'l').text()).toContain('Abschluss 15.11.2026');
    });

    it('rolls a failed move back to its place and says so', async () => {
        stubFetch(async () => new Response('', { status: 500 }));
        const wrapper = island({ lost: {} });

        await next(wrapper, 'b').trigger('click');
        await flushPromises();

        expect(cardsIn(wrapper, 'new')).toEqual(['a', 'b']);
        expect(cardsIn(wrapper, 'offer')).toEqual([]);
        expect(columnOf(wrapper, 'new').get('.pill').text()).toBe('2');
        expect(wrapper.get('[role="alert"]').text()).toBe('Verschieben nicht möglich.');
        expect(status(wrapper)).toBe('Deal b bleibt in Neu.');
        expect(document.activeElement).toBe(next(wrapper, 'b').element);
    });

    it('keeps a closed card\'s close and reason through a rollback', async () => {
        stubFetch(async () => new Response('', { status: 500 }));
        const wrapper = island();

        await cardOf(wrapper, 'w').get('[data-deal-target="offer"]').trigger('click');
        await cardOf(wrapper, 'l').get('[data-deal-target="offer"]').trigger('click');
        await flushPromises();

        expect(cardOf(wrapper, 'w').text()).toContain('Gewonnen 21.10.2026');
        expect(cardOf(wrapper, 'l').text()).toContain('Verloren: Budget');
    });

    it('puts the figure of the head back after a rollback', async () => {
        document.body.innerHTML = '<span data-deal-open>Offen: CHF 1\'000 · 2 Deals</span>';
        let finish;
        stubFetch(() => new Promise((resolve) => { finish = resolve; }));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="won"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('[data-deal-open]').textContent).toBe('Offen: CHF 0 · 1 Deal');

        finish(new Response('', { status: 500 }));
        await flushPromises();

        expect(cardsIn(wrapper, 'new')).toEqual(['a', 'b']);
        expect(document.querySelector('[data-deal-open]').textContent).toBe('Offen: CHF 1\'000 · 2 Deals');
    });

    it('offers a reload link when another person moved the deal', async () => {
        stubFetch(async () => json({ errors: { '': ['stale'] } }, 409));
        const wrapper = island();

        await next(wrapper, 'a').trigger('click');
        await flushPromises();

        expect(cardsIn(wrapper, 'new')).toEqual(['a', 'b']);
        expect(wrapper.get('[role="alert"]').text()).toContain('Dieser Deal wurde inzwischen verschoben.');
        expect(wrapper.get('[role="alert"] a').text()).toBe('Seite neu laden');
    });

    it('keeps the heads true through a move and its rollback', async () => {
        let answer = json({ stage: 'offer', closedOn: null });
        stubFetch(async () => answer);
        const wrapper = island({ new: { count: 50, sumRappen: 900000 } });

        await next(wrapper, 'a').trigger('click');
        await flushPromises();

        expect(columnOf(wrapper, 'new').get('.pill').text()).toBe('49');
        expect(columnOf(wrapper, 'new').get('.deal-column__sum').text()).toBe("CHF 8'000");
        expect(columnOf(wrapper, 'offer').get('.deal-column__sum').text()).toBe("CHF 1'000");

        answer = new Response('', { status: 500 });
        await next(wrapper, 'a').trigger('click');
        await flushPromises();

        expect(columnOf(wrapper, 'offer').get('.deal-column__sum').text()).toBe("CHF 1'000");
        expect(columnOf(wrapper, 'won').get('.pill').text()).toBe('1');
    });

    it('counts a deal without an amount as nothing', async () => {
        stubFetch(async () => json({ stage: 'offer', closedOn: null }));
        const wrapper = island();

        await next(wrapper, 'b').trigger('click');
        await flushPromises();

        expect(columnOf(wrapper, 'new').get('.deal-column__sum').text()).toBe("CHF 1'000");
        expect(columnOf(wrapper, 'offer').get('.deal-column__sum').text()).toBe('CHF 0');
    });

    it('updates the figure of the head, which leaves won and lost out', async () => {
        document.body.innerHTML = '<span data-deal-open>Offen: CHF 2\'000 · 2 Deals</span>';
        stubFetch(async () => json({ stage: 'won', closedOn: '2026-11-01' }));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="won"]').trigger('click');
        await flushPromises();

        expect(document.querySelector('[data-deal-open]').textContent).toBe('Offen: CHF 0 · 1 Deal');
    });

    it('announces a move', async () => {
        stubFetch(async () => json({ stage: 'offer', closedOn: null }));
        const wrapper = island();

        await next(wrapper, 'a').trigger('click');
        await flushPromises();

        expect(status(wrapper)).toBe('Deal a ist jetzt in Angebot.');
    });

    it('asks to apply the tier when a won move answers an offer, with a native form', async () => {
        const tierOffer = { action: '/admin/crm/deals/a/tier', token: 'csrf-token', expected: 'basic', text: '«Villa» hat heute den Tier Basic.' };
        stubFetch(async () => json({ stage: 'won', closedOn: '2026-11-01', tierOffer }));
        const wrapper = island();

        await drag(wrapper, 'a', 'won');
        await flushPromises();

        const form = wrapper.get('#deal-tier-form');
        expect(wrapper.get('#deal-tier').attributes('open')).toBeDefined();
        expect(form.text()).toContain('«Villa» hat heute den Tier Basic.');
        expect(form.attributes('method')).toBe('post');
        expect(form.attributes('action')).toBe('/admin/crm/deals/a/tier');
        expect(form.get('[name="_token"]').element.value).toBe('csrf-token');
        expect(form.get('[name="expected"]').element.value).toBe('basic');
        expect(form.get('[name="return_to"]').element.value).toBe(window.location.pathname + window.location.search);
        expect(wrapper.get('#deal-tier button[type="submit"]').attributes('form')).toBe('deal-tier-form');
    });

    it('closes the tier prompt on Nicht jetzt and leaves the card won', async () => {
        const tierOffer = { action: '/admin/crm/deals/a/tier', token: 'csrf-token', expected: 'basic', text: 'Text' };
        stubFetch(async () => json({ stage: 'won', closedOn: '2026-11-01', tierOffer }));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="won"]').trigger('click');
        await flushPromises();
        await wrapper.get('#deal-tier .modal__footer button').trigger('click');
        await flushPromises();

        expect(wrapper.get('#deal-tier').attributes('open')).toBeUndefined();
        expect(cardsIn(wrapper, 'won')).toContain('a');
    });

    it('asks nothing when a won move answers no offer', async () => {
        stubFetch(async () => json({ stage: 'won', closedOn: '2026-11-01', tierOffer: null }));
        const wrapper = island();

        await cardOf(wrapper, 'a').get('[data-deal-target="won"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('#deal-tier').attributes('open')).toBeUndefined();
    });

    it('sends one move per card at a time', async () => {
        let finish;
        const fetch = stubFetch(() => new Promise((resolve) => { finish = resolve; }));
        const wrapper = island();

        await next(wrapper, 'a').trigger('click');
        await next(wrapper, 'a').trigger('click');
        expect(fetch).toHaveBeenCalledTimes(1);

        finish(json({ stage: 'offer', closedOn: null }));
        await flushPromises();
        expect(cardsIn(wrapper, 'offer')).toEqual(['a']);
    });
});
