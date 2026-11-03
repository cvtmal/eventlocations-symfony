<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * One raw event as one line of a spool day file (#47, contract C2). The constructor holds every
 * rule the `stats_events` columns hold (enums, lengths, the click/kind pairing, int venue numbers),
 * so a line that `fromArray()` accepts always inserts. The IP is never part of it.
 */
final readonly class SpoolEvent
{
    public const string ZONE = 'Europe/Zurich';
    public const int MAX_TEXT = 255;
    public const int MAX_VENUES = 100;
    public const int MAX_VENUE_NUMBER = 2147483647;

    /**
     * @param list<int>|null $venues
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public EventType $type,
        public ?ClickKind $kind,
        public ?array $venues,
        public ?PageType $page,
        public ?string $path,
        public ?string $referrerHost,
        public ?string $userAgent,
        public ?string $visitorHash,
        public bool $automated,
    ) {
        if ((EventType::Click === $type) !== (null !== $kind)) {
            throw new \InvalidArgumentException('A click and only a click has a kind.');
        }
        if (null !== $venues) {
            if ([] === $venues || \count($venues) > self::MAX_VENUES) {
                throw new \InvalidArgumentException('Venues are a list of 1 to 100 numbers.');
            }
            foreach ($venues as $number) {
                if ($number < 1 || $number > self::MAX_VENUE_NUMBER) {
                    throw new \InvalidArgumentException('A venue number is a positive int4.');
                }
            }
        }
        foreach ([$path, $referrerHost, $userAgent] as $text) {
            if (null !== $text && (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || mb_strlen($text) > self::MAX_TEXT)) {
                throw new \InvalidArgumentException('A text is valid UTF-8 without NUL, at most 255 characters.');
            }
        }
        if (null !== $visitorHash && 1 !== preg_match('/^[0-9a-f]{32}$/', $visitorHash)) {
            throw new \InvalidArgumentException('A visitor hash is 32 hex digits.');
        }
    }

    /**
     * The inquiry line of C2: no page context and no visitor.
     */
    public static function inquiry(\DateTimeImmutable $at, int $venueNumber): self
    {
        return new self($at, EventType::Inquiry, null, [$venueNumber], null, null, null, null, null, false);
    }

    /**
     * The spool file this event belongs to, `<Y-m-d>` of its moment in Zurich.
     */
    public function day(): string
    {
        return $this->at->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d');
    }

    /**
     * One NDJSON line without its newline.
     */
    public function toJson(): string
    {
        return json_encode([
            'at' => $this->at->setTimezone(new \DateTimeZone(self::ZONE))->format(\DATE_ATOM),
            'type' => $this->type->value,
            'kind' => $this->kind?->value,
            'venues' => $this->venues,
            'page' => $this->page?->value,
            'path' => $this->path,
            'ref' => $this->referrerHost,
            'ua' => $this->userAgent,
            'vh' => $this->visitorHash,
            'bot' => $this->automated,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * A decoded spool line back into an event.
     *
     * @param array<mixed> $line
     *
     * @throws \InvalidArgumentException when the line has the wrong shape or no longer matches the enums
     */
    public static function fromArray(array $line): self
    {
        $at = \is_string($line['at'] ?? null) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $line['at']) : false;
        $type = \is_string($line['type'] ?? null) ? EventType::tryFrom($line['type']) : null;
        if (false === $at || null === $type) {
            throw new \InvalidArgumentException('A spool line needs a valid "at" and "type".');
        }

        $venues = null;
        if (null !== ($line['venues'] ?? null)) {
            if (!\is_array($line['venues']) || !array_is_list($line['venues'])) {
                throw new \InvalidArgumentException('"venues" is null or a list of ints.');
            }
            $venues = [];
            foreach ($line['venues'] as $number) {
                $venues[] = \is_int($number) ? $number : throw new \InvalidArgumentException('"venues" is null or a list of ints.');
            }
        }
        if (!\is_bool($line['bot'] ?? null)) {
            throw new \InvalidArgumentException('"bot" is a boolean.');
        }
        $kind = self::text($line, 'kind');
        $page = self::text($line, 'page');

        return new self(
            $at,
            $type,
            null === $kind ? null : ClickKind::tryFrom($kind) ?? throw new \InvalidArgumentException('Unknown "kind".'),
            $venues,
            null === $page ? null : PageType::tryFrom($page) ?? throw new \InvalidArgumentException('Unknown "page".'),
            self::text($line, 'path'),
            self::text($line, 'ref'),
            self::text($line, 'ua'),
            self::text($line, 'vh'),
            $line['bot'],
        );
    }

    /**
     * @param array<mixed> $line
     */
    private static function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is null or a string.', $key));
        }

        return $value;
    }
}
