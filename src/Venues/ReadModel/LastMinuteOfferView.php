<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;

/**
 * One offer «Kurzfristig frei» (G14, D55) as the detail page, the page «Kurzfristig frei» and the `/konto` tab show it.
 * A one-day offer has `endsOn == startsOn`; `roomName` null is the whole location. `visible` is false on `/konto` only,
 * for an offer the venue's plan hides (the term lapsed).
 */
final readonly class LastMinuteOfferView
{
    /**
     * @param list<Term> $eventTypes
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public ?string $roomName,
        public array $eventTypes,
        public string $priceText,
        public ?int $minGuests,
        public ?string $terms,
        public bool $visible = true,
    ) {
    }

    /**
     * @param array<mixed> $row `id`, `starts_on`, `ends_on`, `event_types` (a list of codes, or its JSON), `price_text`,
     *                          `min_guests`, `terms`; DBAL columns or the keys of a `jsonb_build_object`
     */
    public static function fromRow(array $row, ?string $roomName, bool $visible = true): self
    {
        \assert(\is_string($row['id']) && \is_string($row['starts_on']) && \is_string($row['ends_on']));
        \assert(\is_string($row['price_text']));
        \assert(null === $row['min_guests'] || \is_int($row['min_guests']));
        \assert(null === $row['terms'] || \is_string($row['terms']));
        $codes = \is_string($row['event_types']) ? json_decode($row['event_types'], true, 512, \JSON_THROW_ON_ERROR) : $row['event_types'];
        \assert(\is_array($codes));

        return new self(
            $row['id'],
            new \DateTimeImmutable($row['starts_on']),
            new \DateTimeImmutable($row['ends_on']),
            $roomName,
            array_values(array_map(static function (mixed $code): Term {
                \assert(\is_string($code));

                return new Term(TermGroup::SuitableFor, $code);
            }, $codes)),
            $row['price_text'],
            $row['min_guests'],
            $row['terms'],
            $visible,
        );
    }

    public function isOneDay(): bool
    {
        return $this->startsOn == $this->endsOn;
    }
}
