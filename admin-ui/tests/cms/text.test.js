import { describe, expect, it } from 'vitest';
import { RequestFailed, SessionGone } from '../../src/api.js';
import { charCount, failureOf, fill } from '../../src/cms/text.js';

const labels = { sessionGone: 'gone', stale: 'stale', notFound: 'missing', failed: 'failed' };

describe('failureOf', () => {
    it.each([
        ['a lost session', new SessionGone(), { message: 'gone', reload: true }],
        ['a dead token', new RequestFailed(403), { message: 'gone', reload: true }],
        ['a stale row', new RequestFailed(409), { message: 'stale', reload: true }],
        ['a missing row', new RequestFailed(404), { message: 'missing', reload: true }],
        ['a server error', new RequestFailed(500), { message: 'failed', reload: false }],
        ['a network failure', new RequestFailed(0), { message: 'failed', reload: false }],
        ['anything else', new Error('x'), { message: 'failed', reload: false }],
    ])('maps %s', (_, error, failure) => {
        expect(failureOf(error, labels)).toEqual(failure);
    });
});

describe('fill', () => {
    it('takes a value literally', () => {
        expect(fill('%name wird gelöscht.', { name: 'Villa $& Co' })).toBe('Villa $& Co wird gelöscht.');
    });
});

describe('charCount', () => {
    it('counts trimmed code points', () => {
        expect(charCount('  Zürich 😀 ')).toBe(8);
        expect(charCount(null)).toBe(0);
    });
});
