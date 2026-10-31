// The small rules of a timeline entry. `occurredAt` is Zurich local time in the composer's format,
// `2026-10-31T14:05`, so it sorts as a string and is shown without any time-zone arithmetic.
const ZONE = 'Europe/Zurich';

// "sv-SE" writes ISO dates. Zurich, not the browser: the server reads the field as Zurich time.
export function nowInZurich(now = new Date()) {
    return new Intl.DateTimeFormat('sv-SE', { timeZone: ZONE, dateStyle: 'short', timeStyle: 'short' }).format(now).replace(' ', 'T');
}

export function displayDate(occurredAt) {
    const [date, time] = occurredAt.split('T');
    const [year, month, day] = date.split('-');

    return `${day}.${month}.${year}, ${time}`;
}

export function monthOf(occurredAt) {
    return occurredAt.slice(0, 7);
}

export function monthLabel(month, locale) {
    const [year, number] = month.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(Date.UTC(year, number - 1, 1));
}

// The server's order: newest first, the id breaks a tie.
export function isNewer(entry, other) {
    return entry.occurredAt === other.occurredAt ? entry.id > other.id : entry.occurredAt > other.occurredAt;
}

// `__name__` tokens, in one pass, so a value that looks like a token stays text.
export function fill(label, values) {
    return label.replace(/__(\w+?)__/g, (token, name) => values[name] ?? token);
}
