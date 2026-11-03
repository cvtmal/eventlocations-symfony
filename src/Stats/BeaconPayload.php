<?php

declare(strict_types=1);

namespace App\Stats;

use App\Stats\ValueObject\ClickKind;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a beacon's form body into spool events (#47, contract C1). One page context per request
 * (`p`, `v`, `u`, `r`, `w`), up to 50 events in `e[]`. Nothing here throws on input: a bad context
 * value is dropped or falls back, a malformed event is dropped, and the rest are kept.
 */
final class BeaconPayload
{
    public const int MAX_EVENTS = 50;
    private const string EVENT = '/^(view|imp|click|add)(?::([a-z]+|\d{1,10}(?:\.\d{1,10}){0,99}))?$/';
    private const string NUMBER = '/^[1-9]\d{0,9}$/';

    /**
     * @return list<SpoolEvent>
     */
    public static function fromRequest(Request $request, \DateTimeImmutable $now, string $visitorHash): array
    {
        $body = $request->request->all();
        $events = $body['e'] ?? null;
        if (!\is_array($events) || [] === $events) {
            return [];
        }

        $page = PageType::tryFrom(self::field($body, 'p') ?? '') ?? PageType::Other;
        $venue = self::number(self::field($body, 'v'));
        $context = [
            'page' => $page,
            'path' => self::clip(self::field($body, 'u')),
            'referrer' => self::referrerHost(self::field($body, 'r'), $request->getHost()),
            'userAgent' => self::clip($request->headers->get('User-Agent')),
            'visitorHash' => $visitorHash,
            'automated' => '1' === self::field($body, 'w'),
        ];

        $parsed = [];
        foreach (\array_slice(array_values($events), 0, self::MAX_EVENTS) as $event) {
            if (!\is_string($event) || 1 !== preg_match(self::EVENT, $event, $match)) {
                continue;
            }
            $argument = $match[2] ?? null;

            $parsed[] = match ($match[1]) {
                'view' => null === $argument ? self::event($now, EventType::PageView, null, PageType::Detail === $page && null !== $venue ? [$venue] : null, $context) : null,
                'imp' => self::impression($now, $argument, $context),
                'click' => null !== $venue && null !== ($kind = ClickKind::tryFrom($argument ?? '')) ? self::event($now, EventType::Click, $kind, [$venue], $context) : null,
                'add' => null !== ($number = self::number($argument)) ? self::event($now, EventType::MerklisteAdd, null, [$number], $context) : null,
            };
        }

        return array_values(array_filter($parsed));
    }

    /**
     * @param array{page: PageType, path: ?string, referrer: ?string, userAgent: ?string, visitorHash: string, automated: bool} $context
     */
    private static function impression(\DateTimeImmutable $now, ?string $argument, array $context): ?SpoolEvent
    {
        $numbers = [];
        foreach (explode('.', $argument ?? '') as $part) {
            $number = self::number($part);
            if (null !== $number) {
                $numbers[$number] = $number;
            }
        }

        return [] === $numbers ? null : self::event($now, EventType::Impression, null, array_values($numbers), $context);
    }

    /**
     * @param list<int>|null                                                                                                    $venues
     * @param array{page: PageType, path: ?string, referrer: ?string, userAgent: ?string, visitorHash: string, automated: bool} $context
     */
    private static function event(\DateTimeImmutable $now, EventType $type, ?ClickKind $kind, ?array $venues, array $context): SpoolEvent
    {
        return new SpoolEvent($now, $type, $kind, $venues, $context['page'], $context['path'], $context['referrer'], $context['userAgent'], $context['visitorHash'], $context['automated']);
    }

    /**
     * @param array<mixed> $body
     */
    private static function field(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function number(?string $value): ?int
    {
        if (null === $value || 1 !== preg_match(self::NUMBER, $value)) {
            return null;
        }
        $number = (int) $value;

        return $number <= SpoolEvent::MAX_VENUE_NUMBER ? $number : null;
    }

    /**
     * Only the host of the referrer, and none for our own pages.
     */
    private static function referrerHost(?string $referrer, string $ownHost): ?string
    {
        $host = null === $referrer ? null : parse_url($referrer, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return null;
        }
        $host = strtolower($host);

        return $host === strtolower($ownHost) ? null : self::clip($host);
    }

    /**
     * Valid UTF-8 without NUL, at most 255 characters: what the `stats_events` text columns take.
     */
    private static function clip(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = mb_substr(str_replace("\0", '', mb_scrub($value, 'UTF-8')), 0, SpoolEvent::MAX_TEXT);

        return '' === $value ? null : $value;
    }
}
