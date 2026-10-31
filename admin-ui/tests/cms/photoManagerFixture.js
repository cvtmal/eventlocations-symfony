import { mount } from '@vue/test-utils';
import { vi } from 'vitest';
import PhotoManagerIsland from '../../src/islands/PhotoManagerIsland.vue';

const ID = '00000000-0000-7000-8000-000000000000';

export function photo(id, position, overrides = {}) {
    return {
        id,
        roomId: null,
        position,
        thumbnailUrl: `/media/1/${id}-640.jpg`,
        width: 2400,
        height: 1600,
        dominantColor: '#8a7f6b',
        widths: [360, 640, 1920],
        formats: ['webp', 'jpg'],
        alts: { de: `Alt ${id}` },
        token: `token-${id}`,
        ...overrides,
    };
}

const labels = new Proxy({
    item: 'Foto %position',
    listLabel: 'Fotos: %scope',
    countOne: '1 Foto',
    countOther: '%count Fotos',
    moved: '%label ist jetzt an Position %position.',
    detailsTitle: 'Foto %position',
    altFallback: 'Ohne Text: Villa, Bild %position.',
    altLabel: 'Alt-Text (%locale)',
    detailsSize: '%width × %height px',
    detailsVariants: '%formats · %min–%max px',
    queueSessionGone: 'Neu laden, dann nochmals: %files',
}, { get: (target, key) => target[key] ?? key });

export function mounted(photos = [photo('a', 1), photo('b', 2), photo('c', 3)]) {
    return mount(PhotoManagerIsland, {
        attachTo: document.body,
        props: {
            photos,
            rooms: [{ id: 'room-1', name: 'Seesaal', position: 1 }],
            locales: [{ code: 'de', label: 'Deutsch' }],
            defaultLocale: 'de',
            venueName: 'Villa',
            maxBytes: 25_000_000,
            accept: 'image/jpeg,image/png,image/webp',
            altMax: 700,
            idPlaceholder: ID,
            reloadUrl: '/admin/locations/1/fotos',
            endpoints: {
                upload: '/api/photos',
                order: '/api/photos/order',
                alt: `/api/photos/${ID}/alt`,
                room: `/api/photos/${ID}/room`,
                remove: `/api/photos/${ID}`,
            },
            labels,
        },
    });
}

export const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

export function answering(...responses) {
    const fetchMock = vi.fn(async () => responses.shift());

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

export const sent = (fetchMock, call = 0) => JSON.parse(fetchMock.mock.calls[call][1].body);
export const tile = (island, index) => island.findAll('.photo-tile')[index];

export async function dropFiles(island, ...files) {
    const input = island.get('input[type=file]');

    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
}
