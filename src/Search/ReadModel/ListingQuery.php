<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\EntitlementResolver;
use App\Venues\PublicPhotoPolicy;
use App\Venues\ValueObject\Entitlements;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The venue listing (LP-1…LP-4): one page of cards and the total, in at most three queries
 * (cards, their photos, the count; two when the page is empty).
 *
 * Order (S6): the tier's rank weight first (a Pro entry before a Free one, F12), then a shuffle that is stable for a
 * day and changes with the date (md5 of the venue id and today's date in Europe/Zurich), then the id so the order
 * is total.
 */
final class ListingQuery
{
    public const PER_PAGE = 48;
    private const PHOTOS_PER_CARD = 8;

    /**
     * The columns of a card and the join to its town, shared by every card query so that they
     * cannot drift. The town is optional (#21), hence the LEFT JOIN, in one place for both.
     *
     * The capacity follows the guest filter (F5b): the range spans the venue's own range and
     * every room range with a maximum, so a venue listed for N guests shows a range holding N.
     * capacity_max is the greatest maximum; capacity_min is the least minimum, NULL ("up to")
     * once one of those ranges states none. The event type (F5b, for the photo placeholder) is
     * the venue's first suitable_for code in vocabulary order, NULL without one (the group name
     * is written out because a const cannot interpolate TermGroup::SuitableFor->value).
     */
    private const CARD_COLUMNS = <<<'SQL'
        v.id, v.number, v.name,
            CASE WHEN v.capacity_min IS NULL THEN NULL ELSE (
                SELECT CASE WHEN bool_or(r.capacity_min IS NULL) THEN NULL ELSE LEAST(v.capacity_min, min(r.capacity_min)) END
                FROM rooms r WHERE r.venue_id = v.id AND r.capacity_max IS NOT NULL
            ) END AS capacity_min,
            GREATEST(v.capacity_max, (SELECT max(r.capacity_max) FROM rooms r WHERE r.venue_id = v.id)) AS capacity_max,
            (
                SELECT x.term_code FROM venue_terms x
                JOIN taxonomy_terms tt ON tt.term_group = x.term_group AND tt.code = x.term_code
                WHERE x.venue_id = v.id AND x.term_group = 'suitable_for'
                ORDER BY tt.primary_position NULLS LAST, tt.position
                LIMIT 1
            ) AS event_type,
            tw.name AS town_name, v.canton_code, t.region, t.slug, t.summary, v.price_level, t.ambience
        SQL;
    private const CARD_TOWN_JOIN = 'LEFT JOIN towns tw ON tw.id = v.town_id';

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $enabledLocale,
        private readonly ClockInterface $clock,
        private readonly EntitlementResolver $entitlements,
    ) {
    }

    public function results(SearchCriteria $criteria, string $locale): ListingPage
    {
        $statement = $this->cardStatement($criteria, $locale);
        $rows = $this->connection->fetchAllAssociative($statement->sql, $statement->params, $statement->types);

        return ListingPage::of($this->cards($rows, $locale), $this->count($criteria), $criteria->page, self::PER_PAGE);
    }

    /**
     * The first venues of the unfiltered listing: results()'s order, there is no second one
     * (HP-4, the home page's Spotlight). Two queries, one when no venue is published.
     *
     * @return list<ListingCard>
     */
    public function first(int $limit, int $photosPerCard, string $locale): array
    {
        $statement = $this->statementFor(SearchCriteria::none(), $locale, $limit);
        $rows = $this->connection->fetchAllAssociative($statement->sql, $statement->params, $statement->types);

        return $this->cards($rows, $locale, $photosPerCard);
    }

    /**
     * The published venues among the given numbers, in the given order (the Merkliste's saved
     * order). Unknown and unpublished numbers are left out, so the caller can see what is stale.
     * Two queries, none for an empty list. #52 asks for one photo per card.
     *
     * @param list<int> $numbers
     *
     * @return list<ListingCard>
     */
    public function cardsByNumbers(array $numbers, string $locale, int $photosPerCard = self::PHOTOS_PER_CARD): array
    {
        if ([] === $numbers) {
            return [];
        }

        $filter = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $columns = self::CARD_COLUMNS;
        $townJoin = self::CARD_TOWN_JOIN;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT {$columns}
                FROM {$filter->from}
                {$townJoin}
                {$translation}
                WHERE {$filter->where} AND v.number IN (:numbers)
                SQL,
            $filter->params + $this->translationJoin->parameters($locale) + ['numbers' => $numbers],
            $filter->types + ['numbers' => ArrayParameterType::INTEGER],
        );

        $position = array_flip($numbers);
        usort($rows, static function (array $a, array $b) use ($position): int {
            \assert(\is_int($a['number']) && \is_int($b['number']));

            return $position[$a['number']] <=> $position[$b['number']];
        });

        return $this->cards($rows, $locale, $photosPerCard);
    }

    public function count(SearchCriteria $criteria): int
    {
        $statement = $this->countStatement($criteria);
        $count = $this->connection->fetchOne($statement->sql, $statement->params, $statement->types);
        \assert(\is_int($count));

        return $count;
    }

    public function cardStatement(SearchCriteria $criteria, string $locale): SqlStatement
    {
        return $this->statementFor($criteria, $locale, self::PER_PAGE);
    }

    private function statementFor(SearchCriteria $criteria, string $locale, int $limit): SqlStatement
    {
        $filter = new ListingSql($criteria, $this->enabledLocale->default());
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $columns = self::CARD_COLUMNS;
        $townJoin = self::CARD_TOWN_JOIN;

        // The page is cut first and the card columns (translation, town, the room and event
        // subqueries) are computed for its rows only: on the outer level they would run for every
        // row up to the offset, which made page 11 four times slower once F5b added subqueries.
        $sql = <<<SQL
            {$filter->with}
            SELECT {$columns}
            FROM (
                SELECT v.*, rank_tier.rank_weight FROM {$filter->from}
                JOIN tiers rank_tier ON rank_tier.id = v.tier_id
                WHERE {$filter->where}
                ORDER BY rank_tier.rank_weight DESC, md5(v.id::text || :seed), v.id
                LIMIT :limit OFFSET :offset
            ) v
            {$townJoin}
            {$translation}
            ORDER BY v.rank_weight DESC, md5(v.id::text || :seed), v.id
            SQL;

        return new SqlStatement(
            $sql,
            $filter->params + $this->translationJoin->parameters($locale) + [
                'seed' => $this->seed(),
                'limit' => $limit,
                'offset' => ($criteria->page - 1) * self::PER_PAGE,
            ],
            $filter->types,
        );
    }

    public function countStatement(SearchCriteria $criteria): SqlStatement
    {
        $filter = new ListingSql($criteria, $this->enabledLocale->default());

        return new SqlStatement(
            <<<SQL
                {$filter->with}
                SELECT count(*) FROM {$filter->from}
                WHERE {$filter->where}
                SQL,
            $filter->params,
            $filter->types,
        );
    }

    private function seed(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('Y-m-d');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ListingCard>
     */
    private function cards(array $rows, string $locale, int $photosPerCard = self::PHOTOS_PER_CARD): array
    {
        $numbers = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['id']));
            \assert(\is_int($row['number']));
            $numbers[$row['id']] = $row['number'];
        }
        $photos = [] === $numbers ? [] : $this->photos($numbers, $locale, $photosPerCard);
        $entitlements = $this->entitlements->forVenues(array_keys($numbers), $this->clock->now());

        return array_map(fn (array $row): ListingCard => $this->card($row, $photos, $entitlements), $rows);
    }

    /**
     * The first venue-level photos (room photos belong to the room cards) per venue.
     *
     * @param array<string, int> $numbers venue id → venue number
     *
     * @return array<string, list<CardPhoto>> venue id → photos in position order
     */
    private function photos(array $numbers, string $locale, int $limit): array
    {
        $translation = $this->translationJoin->localized('venue_photo_translations', 'venue_photo_id', 'p.id', 'pt');
        $granted = PublicPhotoPolicy::sql('vp');

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT ids.venue_id, to_jsonb(p) AS photo, pt.alt
                FROM unnest(ARRAY[:ids]::uuid[]) AS ids(venue_id)
                CROSS JOIN LATERAL (
                    SELECT vp.* FROM venue_photos vp
                    WHERE vp.venue_id = ids.venue_id AND vp.room_id IS NULL AND {$granted}
                    ORDER BY vp.position
                    LIMIT {$limit}
                ) p
                {$translation}
                ORDER BY ids.venue_id, p.position
                SQL,
            ['ids' => array_keys($numbers)] + $this->translationJoin->parameters($locale),
            ['ids' => ArrayParameterType::STRING],
        );

        $photos = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['venue_id']));
            \assert(\is_string($row['photo']));
            \assert(null === $row['alt'] || \is_string($row['alt']));
            $photo = json_decode($row['photo'], true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($photo));
            $photos[$row['venue_id']][] = CardPhoto::fromRow($numbers[$row['venue_id']], $photo, $row['alt']);
        }

        return $photos;
    }

    /**
     * @param array<string, mixed>           $row
     * @param array<string, list<CardPhoto>> $photos
     * @param array<string, Entitlements>    $entitlements venue id → what the venue's tier allows
     */
    private function card(array $row, array $photos, array $entitlements): ListingCard
    {
        \assert(\is_string($row['id']));
        \assert(\is_int($row['number']));
        \assert(\is_string($row['slug']));
        \assert(\is_string($row['name']));
        \assert(null === $row['capacity_min'] || \is_int($row['capacity_min']));
        \assert(\is_int($row['capacity_max']));
        \assert(null === $row['town_name'] || \is_string($row['town_name']));
        \assert(null === $row['canton_code'] || \is_string($row['canton_code']));
        \assert(null === $row['region'] || \is_string($row['region']));
        \assert(\is_string($row['summary']));
        \assert(null === $row['price_level'] || \is_string($row['price_level']));
        \assert(null === $row['ambience'] || \is_string($row['ambience']));
        \assert(null === $row['event_type'] || \is_string($row['event_type']));

        return new ListingCard(
            $row['id'],
            $row['number'],
            $row['slug'],
            $row['name'],
            $entitlements[$row['id']]->isPromoted(),
            $row['capacity_min'],
            $row['capacity_max'],
            VenuePlace::of($row['town_name'], $row['canton_code'], $row['region']),
            $row['summary'],
            null === $row['price_level'] ? null : PriceLevel::from($row['price_level']),
            $row['ambience'],
            \array_slice($photos[$row['id']] ?? [], 0, $entitlements[$row['id']]->photosMax),
            $row['event_type'],
        );
    }
}
