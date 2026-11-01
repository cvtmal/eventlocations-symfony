import { describe, expect, it } from 'vitest';
import fixture from '../fixtures/chf.json';
import { formatChf, groupDigits } from '../../src/crm/money.js';

// `tests/Unit/Crm/ChfFixtureTest.php` holds PHP to the same file (N6).
describe('money', () => {
    it.each(fixture.vectors)('formats $rappen Rappen as $text', ({ rappen, text }) => {
        expect(formatChf(rappen)).toBe(text);
    });

    it('groups with the group character of the fixture', () => {
        expect(groupDigits(1234567)).toBe(`1${fixture.group}234${fixture.group}567`);
        expect(groupDigits(999)).toBe('999');
    });
});
