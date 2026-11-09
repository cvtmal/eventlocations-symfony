<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\LastMinuteOfferPolicy;
use App\Venues\ReadModel\LastMinuteOfferView;
use App\Venues\ValueObject\TermDay;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The page «Kurzfristig frei» (G14, D55): the live offers of published venues (`LastMinuteOfferPolicy`, the Zurich day
 * of the clock), grouped by venue, the venue with the soonest offer first, then by name; at most `LIMIT` offers. Three
 * queries: the offers, then the cards (`ListingQuery::cardsByNumbers()`, one photo each); none of the cards when there
 * is no offer. A venue whose card is missing (no translation) is left out.
 */
final class LastMinutePageQuery
{
    public const int LIMIT = 60;

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $locales,
        private readonly ListingQuery $listing,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<LastMinuteEntry>
     */
    public function entries(string $locale): array
    {
        $translation = $this->translationJoin->localized('room_translations', 'room_id', 'o.room_id', 'rt');
        $visible = LastMinuteOfferPolicy::sql('o', 'lm_today');
        $limit = self::LIMIT;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT v.number, v.inquiry_email IS NOT NULL AS inquirable, o.id, o.starts_on, o.ends_on, o.event_types, o.price_text, o.min_guests, o.terms, rt.name AS room_name
                FROM last_minute_offers o
                JOIN venues v ON v.id = o.venue_id
                {$translation}
                WHERE v.status = 'published' AND {$visible}
                ORDER BY o.starts_on, v.name, o.ends_on, o.id
                LIMIT {$limit}
                SQL,
            ['lm_today' => TermDay::sql($this->clock->now())] + $this->translationJoin->parameters($locale),
        );

        $offers = [];
        $inquirable = [];
        foreach ($rows as $row) {
            \assert(\is_int($row['number']));
            $inquirable[$row['number']] = (bool) $row['inquirable'];
            \assert(null === $row['room_name'] || \is_string($row['room_name']));
            $offers[$row['number']][] = LastMinuteOfferView::fromRow($row, $row['room_name']);
        }
        if ([] === $offers) {
            return [];
        }

        $entries = [];
        foreach ($this->listing->cardsByNumbers(array_keys($offers), $locale, 1) as $card) {
            $entries[] = new LastMinuteEntry($card, $offers[$card->number], $inquirable[$card->number]);
        }

        return $entries;
    }

    /**
     * Whether the page lists anything: the sitemap names it only then, and an empty page is `noindex`. One query.
     */
    public function hasAny(): bool
    {
        $visible = LastMinuteOfferPolicy::sql('o', 'lm_today');
        $default = $this->locales->default();

        return (bool) $this->connection->fetchOne(
            <<<SQL
                SELECT EXISTS (
                    SELECT 1 FROM last_minute_offers o
                    JOIN venues v ON v.id = o.venue_id
                    WHERE v.status = 'published' AND {$visible}
                        AND EXISTS (SELECT 1 FROM venue_translations vt WHERE vt.venue_id = v.id AND vt.locale = :default_locale)
                )
                SQL,
            ['lm_today' => TermDay::sql($this->clock->now()), 'default_locale' => $default],
        );
    }
}
