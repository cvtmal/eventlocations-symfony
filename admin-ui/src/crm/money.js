// `Chf::format()` in JS, without Intl, whose `de-CH` need not match `swiss_number` (N6). `tests/fixtures/chf.json` holds
// both sides to the same text. Rappen are integers, and N10's limit keeps every sum of a board safe in a Number.
const GROUP = "'";

export function groupDigits(number) {
    return String(number).replace(/\B(?=(\d{3})+(?!\d))/g, GROUP);
}

export function formatChf(rappen) {
    const cents = rappen % 100;
    const francs = groupDigits(Math.floor(rappen / 100));

    return cents === 0 ? francs : `${francs}.${String(cents).padStart(2, '0')}`;
}
