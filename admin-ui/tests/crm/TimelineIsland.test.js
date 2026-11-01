import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { displayDate, fill, isNewer, monthLabel, nowInZurich } from '../../src/crm/entries.js';
import TimelineIsland from '../../src/islands/TimelineIsland.vue';

const labels = {
    section: 'Aktivitäten',
    filters: 'Aktivitätstypen',
    all: 'Aktivitäten',
    empty: 'Noch keine Aktivitäten.',
    emptyFiltered: 'Keine Einträge dieser Art.',
    more: 'Mehr laden',
    anonymised: 'Inhalt entfernt',
    composer: 'Neue Aktivität',
    composerTabs: 'Art',
    date: 'Datum',
    saved: 'Eintrag gespeichert.',
    dueOn: 'Fällig am',
    assignee: 'Zuständig',
    edit: 'Bearbeiten',
    editBody: 'Text des Eintrags',
    save: 'Speichern',
    cancel: 'Abbrechen',
    delete: 'Löschen',
    deleteTitle: 'Eintrag löschen?',
    deleteText: 'Der Eintrag wird endgültig gelöscht.',
    deleteConfirm: 'Endgültig löschen',
    deleted: 'Eintrag gelöscht.',
    close: 'Schliessen',
    reload: 'Seite neu laden',
    sessionGone: 'Sitzung abgelaufen.',
    error: 'Das hat nicht geklappt.',
    notFound: 'Dieser Eintrag existiert nicht mehr.',
    forbidden: 'Diese Seite ist nicht mehr aktuell.',
    stale: 'Das hat nicht geklappt.',
    inquiryOpen: 'Anfrage öffnen',
    inquiryEntry: 'von __couple__ für __venue__',
    by: '__type__ von __actor__',
    system: {
        published: '«__venue__» publiziert',
        unpublished: '«__venue__» unpubliziert',
        tier_changed: '«__venue__»: neu __tier__',
        deal_stage_changed: '__actor__ hat den Deal «__deal__» auf __stage__ verschoben',
    },
    dealStageChangedAnonymous: 'Deal «__deal__» auf __stage__ verschoben',
    stages: { negotiation: 'Verhandlung' },
    tiers: { premium: 'Premium' },
};

const types = [
    { code: 'note', label: 'Notiz', tab: 'Notizen' },
    { code: 'email_logged', label: 'E-Mail', tab: 'E-Mails' },
    { code: 'call', label: 'Anruf', tab: 'Anrufe' },
    { code: 'meeting', label: 'Meeting', tab: 'Meetings' },
    { code: 'inquiry', label: 'Anfrage', tab: 'Anfragen' },
];

const composer = [
    { code: 'note', label: 'Notiz', placeholder: 'Notiz schreiben …', submit: 'Notiz speichern' },
    { code: 'email_logged', label: 'E-Mail', placeholder: 'Was stand in der E-Mail?', submit: 'E-Mail erfassen' },
    { code: 'call', label: 'Anruf', placeholder: 'Worum ging es im Anruf?', submit: 'Anruf erfassen' },
    { code: 'meeting', label: 'Meeting', placeholder: 'Worum ging es im Meeting?', submit: 'Meeting erfassen' },
    { code: 'task', label: 'Aufgabe', placeholder: 'Was ist zu tun?', submit: 'Aufgabe erstellen' },
];

const urls = { activities: '/admin/api/crm/activities', timeline: '/admin/api/crm/companies/c1/timeline', tasks: '/admin/api/crm/tasks', inquiries: '/admin/crm/anfragen' };

function entry(id, occurredAt, overrides = {}) {
    return {
        kind: 'activity',
        id,
        type: 'note',
        occurredAt,
        actorName: 'Andrea Keller',
        title: null,
        body: `Text ${id}`,
        anonymised: false,
        canEdit: false,
        venueNumber: null,
        venueName: null,
        tier: null,
        ...overrides,
    };
}

function inquiry(overrides = {}) {
    return entry('e1', '2026-10-29T11:48', { type: 'inquiry', actorName: null, title: 'Schloss Seeblick', body: null, inquiryId: 'i1', inquiryCouple: 'Sina Huber', ...overrides });
}

function json(body, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function island(page = { entries: [], next: null }) {
    return mount(TimelineIsland, {
        props: {
            kind: 'company',
            recordId: 'c1',
            page,
            types,
            composer,
            assignees: [{ id: 'u1', name: 'Andrea Keller' }, { id: 'u2', name: 'Marc Widmer' }],
            viewerId: 'u2',
            urls,
            labels,
            locale: 'de',
        },
        attachTo: document.body,
    });
}

const tab = (wrapper, name) => wrapper.findAll('[role="tab"]').find((button) => button.text() === name);
const filter = (wrapper, name) => wrapper.findAll('.crm-timeline__filter').find((button) => button.text() === name);
const button = (wrapper, name) => wrapper.findAll('button').find((candidate) => candidate.text() === name);
const titles = (wrapper) => wrapper.findAll('.timeline-item__title').map((title) => title.text());
const bodies = (wrapper) => wrapper.findAll('.timeline-item__content .muted').map((body) => body.text());
const sent = (fetch, method) => fetch.mock.calls.filter(([, init]) => init.method === method);
const sentBody = (fetch, method) => JSON.parse(sent(fetch, method).at(-1)[1].body);

describe('TimelineIsland', () => {
    let wrapper;
    let fetch;

    beforeEach(() => {
        fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
    });

    afterEach(() => {
        wrapper?.unmount();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.replaceChildren();
    });

    it('shows the first page by month, with no request', () => {
        wrapper = island({
            entries: [
                entry('e3', '2026-10-29T14:02', { type: 'call', body: 'Will verlängern' }),
                entry('e2', '2026-10-27T10:30', { kind: 'system', type: 'published', venueName: 'Schloss Seeblick', body: null }),
                entry('e1', '2026-09-30T09:00', { kind: 'system', type: 'tier_changed', venueName: 'Schloss Seeblick', tier: 'premium', actorName: null, body: null }),
            ],
            next: null,
        });

        expect(wrapper.findAll('.crm-timeline__month').map((heading) => heading.text())).toEqual(['Oktober 2026', 'September 2026']);
        expect(wrapper.get('.timeline-item__title').text()).toBe('Anruf von Andrea Keller');
        expect(wrapper.get('.timeline-item__meta').text()).toBe('29.10.2026, 14:02');
        expect(wrapper.get('.timeline-item__icon').classes()).toContain('timeline-item__icon--green');
        expect(wrapper.findAll('.timeline-item--system').map((item) => item.text())).toEqual([
            '«Schloss Seeblick» publiziert · Andrea Keller · 27.10.2026, 10:30',
            '«Schloss Seeblick»: neu Premium · 30.09.2026, 09:00',
        ]);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('writes a stage change as the design\'s sentence, with or without an actor', () => {
        wrapper = island({
            entries: [
                entry('e2', '2026-10-27T10:30', { kind: 'system', type: 'deal_stage_changed', title: 'Verlängerung 2027', stage: 'negotiation', body: null }),
                entry('e1', '2026-10-26T09:00', { kind: 'system', type: 'deal_stage_changed', title: 'Premium', stage: 'negotiation', actorName: null, body: null }),
            ],
            next: null,
        });

        expect(wrapper.findAll('.timeline-item--system').map((item) => item.text())).toEqual([
            'Andrea Keller hat den Deal «Verlängerung 2027» auf Verhandlung verschoben · 27.10.2026, 10:30',
            'Deal «Premium» auf Verhandlung verschoben · 26.10.2026, 09:00',
        ]);
    });

    it('draws a body as text, line breaks included', () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { body: '<img src=x onerror=alert(1)>\nzweite Zeile' })], next: null });

        const body = wrapper.get('.timeline-item__content .muted');
        expect(body.element.textContent).toBe('<img src=x onerror=alert(1)>\nzweite Zeile');
        expect(body.element.children).toHaveLength(0);
    });

    it('shows an anonymised entry without text and without actions', () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { body: null, anonymised: true })], next: null });

        expect(bodies(wrapper)).toEqual(['Inhalt entfernt']);
        expect(button(wrapper, 'Bearbeiten')).toBeUndefined();
    });

    it('writes an inquiry as the design\'s sentence, with a link to the inbox', () => {
        wrapper = island({ entries: [inquiry()], next: null });

        expect(titles(wrapper)).toEqual(['Anfrage']);
        expect(bodies(wrapper)).toEqual(['von Sina Huber für Schloss Seeblick']);
        expect(wrapper.get('.timeline-item__icon').classes()).toContain('timeline-item__icon--pink');
        const link = wrapper.get('.timeline-item a');
        expect(link.text()).toBe('Anfrage öffnen');
        expect(link.attributes('href')).toBe('/admin/crm/anfragen/i1');
        expect(button(wrapper, 'Bearbeiten')).toBeUndefined();
    });

    it('shows the venue alone and no link once the inquiry is purged', () => {
        wrapper = island({ entries: [inquiry({ inquiryId: null, inquiryCouple: null })], next: null });

        expect(bodies(wrapper)).toEqual(['Schloss Seeblick']);
        expect(wrapper.find('.timeline-item a').exists()).toBe(false);
    });

    it.each([
        ['as the server sends it', { title: null, inquiryId: null, inquiryCouple: null }],
        ['with its inquiry still attached', { title: null }],
    ])('shows an anonymised inquiry with the label alone, %s', (_, overrides) => {
        wrapper = island({ entries: [inquiry({ anonymised: true, ...overrides })], next: null });

        expect(bodies(wrapper)).toEqual(['Inhalt entfernt']);
        expect(wrapper.find('.timeline-item a').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Sina Huber');
    });

    it('offers edit and delete only on own entries', () => {
        wrapper = island({ entries: [entry('e2', '2026-10-29T14:02', { canEdit: true }), entry('e1', '2026-10-28T14:02')], next: null });

        expect(wrapper.findAll('.crm-timeline__actions')).toHaveLength(1);
        expect(wrapper.findAll('.timeline-item')[0].find('.crm-timeline__actions').exists()).toBe(true);
    });

    it('shows the empty state', () => {
        wrapper = island();

        expect(wrapper.get('.empty__title').text()).toBe('Noch keine Aktivitäten.');
    });

    it.each([
        ['Notiz', 'note', 'Notiz speichern', 'Notiz schreiben …'],
        ['E-Mail', 'email_logged', 'E-Mail erfassen', 'Was stand in der E-Mail?'],
        ['Anruf', 'call', 'Anruf erfassen', 'Worum ging es im Anruf?'],
        ['Meeting', 'meeting', 'Meeting erfassen', 'Worum ging es im Meeting?'],
    ])('logs from the %s tab', async (name, type, submit, placeholder) => {
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ entry: entry('e1', '2026-10-29T14:02', { type, body: 'Gesagt' }) }, 201));

        await tab(wrapper, name).trigger('click');
        expect(tab(wrapper, name).attributes('aria-selected')).toBe('true');
        expect(wrapper.get('textarea').attributes('placeholder')).toBe(placeholder);
        expect(button(wrapper, submit).attributes('type')).toBe('submit');

        await wrapper.get('textarea').setValue('Gesagt');
        await wrapper.get('input[type="datetime-local"]').setValue('2026-10-29T14:02');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'POST')[0][0]).toBe(urls.activities);
        expect(sentBody(fetch, 'POST')).toEqual({ kind: 'company', recordId: 'c1', type, body: 'Gesagt', occurredAt: '2026-10-29T14:02' });
        expect(bodies(wrapper)).toEqual(['Gesagt']);
        expect(wrapper.get('textarea').element.value).toBe('');
    });

    it('logs the moment of the save while the date was not touched', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-31T13:05:00Z'));
        wrapper = island();
        expect(wrapper.get('input[type="datetime-local"]').element.value).toBe('2026-10-31T14:05');

        vi.setSystemTime(new Date('2026-10-31T16:30:00Z'));
        fetch.mockResolvedValueOnce(json({ entry: entry('e1', '2026-10-31T17:30') }, 201));
        await wrapper.get('textarea').setValue('Später');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sentBody(fetch, 'POST').occurredAt).toBe('2026-10-31T17:30');
    });

    it('keeps the text through a tab change', async () => {
        wrapper = island();

        await wrapper.get('textarea').setValue('Halb geschrieben');
        await tab(wrapper, 'Anruf').trigger('click');

        expect(wrapper.get('textarea').element.value).toBe('Halb geschrieben');
    });

    it('shows a 422 under its input and keeps the text', async () => {
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ errors: { body: ['Bitte einen Text eingeben.'], occurredAt: ['Bitte ein gültiges Datum eingeben.'], recordId: ['Diesen Eintrag gibt es nicht.'] } }, 422));

        await wrapper.get('textarea').setValue('  ');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#composer-text-error').text()).toBe('Bitte einen Text eingeben.');
        expect(wrapper.get('textarea').attributes('aria-describedby')).toBe('composer-text-error');
        expect(wrapper.get('#composer-date-error').text()).toBe('Bitte ein gültiges Datum eingeben.');
        expect(wrapper.get('.crm-composer [role="alert"]').text()).toBe('Diesen Eintrag gibt es nicht.');
        expect(wrapper.get('textarea').element.value).toBe('  ');
        expect(titles(wrapper)).toEqual([]);
    });

    it('creates a task from the Aufgabe tab and reloads the page', async () => {
        const reload = vi.fn();
        vi.stubGlobal('location', { href: 'http://localhost/admin/crm/firmen/c1', reload });
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-31T13:05:00Z'));
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ id: 't1' }, 201));

        await tab(wrapper, 'Aufgabe').trigger('click');
        expect(wrapper.get('#composer-due-on').element.value).toBe('2026-10-31');
        expect(wrapper.get('#composer-assignee').element.value).toBe('u2');
        expect(wrapper.find('#composer-date').exists()).toBe(false);

        await wrapper.get('input[type="text"]').setValue('Rückruf');
        await wrapper.get('#composer-due-on').setValue('2026-11-02');
        await wrapper.get('#composer-assignee').setValue('u1');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'POST')[0][0]).toBe(urls.tasks);
        expect(sentBody(fetch, 'POST')).toEqual({ kind: 'company', recordId: 'c1', title: 'Rückruf', dueOn: '2026-11-02', assigneeId: 'u1' });
        expect(reload).toHaveBeenCalledOnce();
    });

    it('shows a refused task under its inputs and does not reload', async () => {
        const reload = vi.fn();
        vi.stubGlobal('location', { href: 'http://localhost/admin/crm/firmen/c1', reload });
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ errors: { title: ['Bitte einen Titel eingeben.'], assigneeId: ['Bitte eine Person wählen.'] } }, 422));

        await tab(wrapper, 'Aufgabe').trigger('click');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#composer-text-error').text()).toBe('Bitte einen Titel eingeben.');
        expect(wrapper.get('#composer-assignee-error').text()).toBe('Bitte eine Person wählen.');
        expect(reload).not.toHaveBeenCalled();
    });

    it('keeps saving after a created task, so a second press in the reload gap sends nothing', async () => {
        const reload = vi.fn();
        vi.stubGlobal('location', { href: 'http://localhost/admin/crm/firmen/c1', reload });
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ id: 't1' }, 201));

        await tab(wrapper, 'Aufgabe').trigger('click');
        await wrapper.get('input[type="text"]').setValue('Einmal');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(reload).toHaveBeenCalledOnce();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'POST')).toHaveLength(1);
        expect(reload).toHaveBeenCalledOnce();
        expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true');
    });

    it('lets a refused task be sent again', async () => {
        const reload = vi.fn();
        vi.stubGlobal('location', { href: 'http://localhost/admin/crm/firmen/c1', reload });
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ errors: { title: ['Bitte einen Titel eingeben.'] } }, 422));
        fetch.mockResolvedValueOnce(json({ id: 't1' }, 201));

        await tab(wrapper, 'Aufgabe').trigger('click');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        await wrapper.get('input[type="text"]').setValue('Rückruf');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'POST')).toHaveLength(2);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('offers a reload when a new note or task meets a 403', async () => {
        wrapper = island();
        fetch.mockResolvedValue(new Response(null, { status: 403 }));

        await wrapper.get('textarea').setValue('Text');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('.crm-composer [role="alert"]').text()).toContain('Diese Seite ist nicht mehr aktuell.');
        expect(wrapper.get('.crm-composer [role="alert"] a').text()).toBe('Seite neu laden');

        await tab(wrapper, 'Aufgabe').trigger('click');
        await wrapper.get('input[type="text"]').setValue('Rückruf');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('.crm-composer [role="alert"]').text()).toContain('Diese Seite ist nicht mehr aktuell.');
        expect(wrapper.get('.crm-composer [role="alert"] a').text()).toBe('Seite neu laden');
    });

    it('ignores a second press on the emptied field after a saved note', async () => {
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ entry: entry('e1', '2026-10-29T14:02') }, 201));
        fetch.mockResolvedValueOnce(json({ errors: { body: ['Bitte einen Wert eingeben.'] } }, 422));

        await wrapper.get('textarea').setValue('Einmal');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'POST')).toHaveLength(1);
        expect(wrapper.find('#composer-text-error').exists()).toBe(false);

        await wrapper.get('textarea').trigger('input');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'POST')).toHaveLength(2);
        expect(wrapper.get('#composer-text-error').text()).toBe('Bitte einen Wert eingeben.');
    });

    it('sends one entry for a double submit', async () => {
        wrapper = island();
        let answer;
        fetch.mockReturnValueOnce(new Promise((resolve) => { answer = resolve; }));

        await wrapper.get('textarea').setValue('Einmal');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');
        answer(json({ entry: entry('e1', '2026-10-29T14:02') }, 201));
        await flushPromises();

        expect(sent(fetch, 'POST')).toHaveLength(1);
    });

    it('edits an own entry with no version in the request', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { canEdit: true })], next: null });
        fetch.mockResolvedValueOnce(json({ entry: entry('e1', '2026-10-29T15:00', { canEdit: true, body: 'Neu' }) }));

        await button(wrapper, 'Bearbeiten').trigger('click');
        const form = wrapper.get('.crm-timeline__edit');
        expect(document.activeElement).toBe(form.get('textarea').element);
        expect(form.get('textarea').element.value).toBe('Text e1');
        expect(form.get('input').element.value).toBe('2026-10-29T14:02');

        await form.get('textarea').setValue('Neu');
        await form.get('input').setValue('2026-10-29T15:00');
        await form.trigger('submit');
        await flushPromises();

        expect(sent(fetch, 'PATCH')[0][0]).toBe('/admin/api/crm/activities/e1');
        expect(sentBody(fetch, 'PATCH')).toEqual({ body: 'Neu', occurredAt: '2026-10-29T15:00' });
        expect(wrapper.find('.crm-timeline__edit').exists()).toBe(false);
        expect(bodies(wrapper)).toEqual(['Neu']);
        expect(document.activeElement).toBe(wrapper.get('#timeline-edit-e1').element);
    });

    it('cancels an edit with Esc', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { canEdit: true })], next: null });

        await button(wrapper, 'Bearbeiten').trigger('click');
        await wrapper.get('.crm-timeline__edit textarea').trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(wrapper.find('.crm-timeline__edit').exists()).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('resorts an entry whose date changed', async () => {
        wrapper = island({ entries: [entry('e2', '2026-10-29T14:02', { canEdit: true }), entry('e1', '2026-10-28T14:02')], next: null });
        fetch.mockResolvedValueOnce(json({ entry: entry('e2', '2026-10-27T14:02', { canEdit: true }) }));

        await button(wrapper, 'Bearbeiten').trigger('click');
        await wrapper.get('.crm-timeline__edit').trigger('submit');
        await flushPromises();

        expect(bodies(wrapper)).toEqual(['Text e1', 'Text e2']);
    });

    it('shows a 422 and a refused edit in the form', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { canEdit: true })], next: null });
        fetch.mockResolvedValueOnce(json({ errors: { body: ['Bitte einen Text eingeben.'] } }, 422));
        fetch.mockResolvedValueOnce(new Response(null, { status: 403 }));

        await button(wrapper, 'Bearbeiten').trigger('click');
        await wrapper.get('.crm-timeline__edit').trigger('submit');
        await flushPromises();
        expect(wrapper.get('#timeline-edit-body-error').text()).toBe('Bitte einen Text eingeben.');

        await wrapper.get('.crm-timeline__edit').trigger('submit');
        await flushPromises();
        const alert = wrapper.get('.crm-timeline__edit [role="alert"]');
        expect(alert.text()).toContain('Diese Seite ist nicht mehr aktuell.');
        expect(alert.get('a').text()).toBe('Seite neu laden');
    });

    it('deletes an own entry after the confirmation', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { canEdit: true })], next: null });
        fetch.mockResolvedValueOnce(new Response(null, { status: 204 }));

        await button(wrapper, 'Löschen').trigger('click');
        expect(fetch).not.toHaveBeenCalled();
        await button(wrapper, 'Endgültig löschen').trigger('click');
        await flushPromises();

        expect(sent(fetch, 'DELETE')[0][0]).toBe('/admin/api/crm/activities/e1');
        expect(titles(wrapper)).toEqual([]);
        expect(document.activeElement).toBe(wrapper.get('.crm-timeline__list').element);
    });

    it('says when the entry to delete is gone, with a reload link', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02', { canEdit: true })], next: null });
        fetch.mockResolvedValueOnce(new Response(null, { status: 404 }));

        await button(wrapper, 'Löschen').trigger('click');
        await button(wrapper, 'Endgültig löschen').trigger('click');
        await flushPromises();

        expect(wrapper.get('#timeline-delete [role="alert"]').text()).toContain('Dieser Eintrag existiert nicht mehr.');
        expect(wrapper.find('#timeline-delete [role="alert"] a').exists()).toBe(true);
        expect(titles(wrapper)).toHaveLength(1);
    });

    it('filters through the endpoint and marks the tab', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02')], next: 'token' });
        fetch.mockResolvedValueOnce(json({ entries: [entry('e2', '2026-10-28T14:02', { type: 'call' })], next: null }));

        await filter(wrapper, 'Anrufe').trigger('click');
        await flushPromises();

        expect(fetch.mock.calls[0][0]).toBe(`${urls.timeline}?type=call`);
        expect(filter(wrapper, 'Anrufe').attributes('aria-pressed')).toBe('true');
        expect(filter(wrapper, 'Anrufe').classes()).toContain('tab--active');
        expect(titles(wrapper)).toEqual(['Anruf von Andrea Keller']);
        expect(button(wrapper, 'Mehr laden')).toBeUndefined();

        fetch.mockResolvedValueOnce(json({ entries: [], next: null }));
        await filter(wrapper, 'Notizen').trigger('click');
        await flushPromises();
        expect(wrapper.get('.empty__title').text()).toBe('Keine Einträge dieser Art.');

        fetch.mockResolvedValueOnce(json({ entries: [entry('e1', '2026-10-29T14:02')], next: 'token' }));
        await filter(wrapper, 'Aktivitäten').trigger('click');
        await flushPromises();
        expect(fetch.mock.calls[2][0]).toBe(urls.timeline);
    });

    it('keeps the list and the tab when a filter fails', async () => {
        wrapper = island({ entries: [entry('e1', '2026-10-29T14:02')], next: null });
        fetch.mockResolvedValueOnce(new Response(null, { status: 500 }));

        await filter(wrapper, 'Anrufe').trigger('click');
        await flushPromises();

        expect(filter(wrapper, 'Aktivitäten').attributes('aria-pressed')).toBe('true');
        expect(bodies(wrapper)).toEqual(['Text e1']);
        expect(wrapper.get('.crm-timeline > [role="alert"]').text()).toBe('Das hat nicht geklappt.');
    });

    it('lets a newer filter win over an older answer', async () => {
        wrapper = island();
        fetch.mockImplementationOnce((url, init) => new Promise((resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        }));
        fetch.mockResolvedValueOnce(json({ entries: [entry('e2', '2026-10-28T14:02', { type: 'meeting' })], next: null }));

        await filter(wrapper, 'Anrufe').trigger('click');
        await filter(wrapper, 'Meetings').trigger('click');
        await flushPromises();

        expect(filter(wrapper, 'Meetings').attributes('aria-pressed')).toBe('true');
        expect(titles(wrapper)).toEqual(['Meeting von Andrea Keller']);
        expect(wrapper.find('.crm-timeline > [role="alert"]').exists()).toBe(false);
    });

    it('loads more with the cursor and the filter, and never repeats an entry', async () => {
        wrapper = island({ entries: [entry('e3', '2026-10-29T14:02'), entry('e2', '2026-10-28T14:02')], next: 'token 1' });
        fetch.mockResolvedValueOnce(json({ entries: [entry('e2', '2026-10-28T14:02'), entry('e1', '2026-09-01T14:02')], next: 'token 2' }));

        await button(wrapper, 'Mehr laden').trigger('click');
        await flushPromises();

        expect(fetch.mock.calls[0][0]).toBe(`${urls.timeline}?before=token+1`);
        expect(bodies(wrapper)).toEqual(['Text e3', 'Text e2', 'Text e1']);
        expect(wrapper.findAll('.crm-timeline__month')).toHaveLength(2);

        fetch.mockResolvedValueOnce(json({ entries: [], next: null }));
        button(wrapper, 'Mehr laden').element.focus();
        await button(wrapper, 'Mehr laden').trigger('click');
        await flushPromises();

        expect(fetch.mock.calls[1][0]).toBe(`${urls.timeline}?before=token+2`);
        expect(button(wrapper, 'Mehr laden')).toBeUndefined();
        expect(document.activeElement).toBe(wrapper.get('.crm-timeline__list').element);
    });

    it('leaves an entry older than the loaded ones to "Mehr laden"', async () => {
        wrapper = island({ entries: [entry('e3', '2026-10-29T14:02')], next: 'token' });
        fetch.mockResolvedValueOnce(json({ entry: entry('e1', '2026-01-01T14:02') }, 201));

        await wrapper.get('textarea').setValue('Alt');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(bodies(wrapper)).toEqual(['Text e3']);
    });

    it('keeps an entry of another type out of a filtered list', async () => {
        wrapper = island();
        fetch.mockResolvedValueOnce(json({ entries: [], next: null }));
        await filter(wrapper, 'Anrufe').trigger('click');
        await flushPromises();

        fetch.mockResolvedValueOnce(json({ entry: entry('e1', '2026-10-29T14:02', { type: 'note' }) }, 201));
        await wrapper.get('textarea').setValue('Notiz');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(titles(wrapper)).toEqual([]);
    });

    it('opens a composer tab from a quick action outside the island', async () => {
        wrapper = island();
        const quick = document.createElement('button');
        quick.dataset.composerTab = 'task';
        quick.innerHTML = '<span>Aufgabe</span>';
        document.body.append(quick);

        quick.firstElementChild.click();
        await flushPromises();

        expect(tab(wrapper, 'Aufgabe').attributes('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(wrapper.get('input[type="text"]').element);

        wrapper.unmount();
        wrapper = null;
        expect(() => quick.click()).not.toThrow();
    });

    it('ignores a quick action for a tab it does not have', async () => {
        wrapper = island();
        const quick = document.createElement('button');
        quick.dataset.composerTab = 'unknown';
        document.body.append(quick);

        quick.click();
        await flushPromises();

        expect(tab(wrapper, 'Notiz').attributes('aria-selected')).toBe('true');
    });

    it('asks for a reload when the session is gone', async () => {
        wrapper = island();
        fetch.mockResolvedValueOnce(new Response(null, { status: 401 }));

        await wrapper.get('textarea').setValue('Text');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        const alert = wrapper.get('.crm-composer [role="alert"]');
        expect(alert.text()).toContain('Sitzung abgelaufen.');
        expect(alert.find('a').exists()).toBe(true);
    });
});

describe('entries', () => {
    it('gives now in Zurich, whatever the browser', () => {
        expect(nowInZurich(new Date('2026-07-01T22:30:00Z'))).toBe('2026-07-02T00:30');
        expect(nowInZurich(new Date('2026-01-01T12:00:00Z'))).toBe('2026-01-01T13:00');
    });

    it('formats a date and a month', () => {
        expect(displayDate('2026-03-05T09:07')).toBe('05.03.2026, 09:07');
        expect(monthLabel('2026-03', 'de')).toBe('März 2026');
    });

    it('orders by date, then id', () => {
        expect(isNewer({ occurredAt: '2026-03-05T09:07', id: 'a' }, { occurredAt: '2026-03-05T09:06', id: 'b' })).toBe(true);
        expect(isNewer({ occurredAt: '2026-03-05T09:07', id: 'a' }, { occurredAt: '2026-03-05T09:07', id: 'b' })).toBe(false);
    });

    it('fills tokens in one pass', () => {
        expect(fill('__type__ von __actor__', { type: '__actor__', actor: 'Andrea' })).toBe('__actor__ von Andrea');
    });
});
