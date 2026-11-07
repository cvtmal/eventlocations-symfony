<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Search\ValueObject\LandingType;
use App\Shared\Translation\EnabledLocale;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The live landing pages (#17): every canton, town and building type with at least
 * `%landing.min_venues%` published venues, by ListingSql's definition of published. One
 * threshold for every type, so a page switches on and off with the data and never needs code.
 * Under the threshold a page does not exist (404), which keeps thin pages out of the index.
 *
 * One statement per request however many methods run (it also feeds #7's "Andere suchten auch nach",
 * so the listing stays at 7 queries); tagged kernel.reset by autoconfiguration. Filling the
 * memo refreshes the footer's cached canton list.
 */
final class LandingPages implements ResetInterface
{
    /** @var list<LandingPage>|null cantons, towns, buildings; each by count desc, then town name or code */
    private ?array $pages = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $enabledLocale,
        private readonly TranslatorInterface $translator,
        private readonly LandingFooter $footer,
        #[Autowire('%landing.min_venues%')]
        private readonly int $minVenues,
    ) {
    }

    /**
     * @return list<LandingPage>
     */
    public function all(): array
    {
        if (null === $this->pages) {
            $this->pages = $this->load($this->statement());
            $this->footer->store($this->cantons());
        }

        return $this->pages;
    }

    /**
     * @return list<LandingPage>
     */
    public function cantons(): array
    {
        return $this->ofType(LandingType::Canton);
    }

    /**
     * @return list<LandingPage>
     */
    public function towns(?Canton $in = null): array
    {
        return array_values(array_filter(
            $this->ofType(LandingType::Town),
            static fn (LandingPage $page): bool => null === $in || $page->canton === $in,
        ));
    }

    /**
     * @return list<LandingPage>
     */
    public function buildings(): array
    {
        return $this->ofType(LandingType::Building);
    }

    public function canton(Canton $canton): ?LandingPage
    {
        return $this->first(static fn (LandingPage $page): bool => LandingType::Canton === $page->type && $page->canton === $canton);
    }

    public function town(string $townId): ?LandingPage
    {
        return $this->first(static fn (LandingPage $page): bool => LandingType::Town === $page->type && $page->townId === $townId);
    }

    public function building(string $code): ?LandingPage
    {
        return $this->first(static fn (LandingPage $page): bool => LandingType::Building === $page->type && $page->building === $code);
    }

    /**
     * The page of `/eventlokale/{slug}`: a canton first, then a building type.
     */
    public function bySlug(string $slug): ?LandingPage
    {
        return $this->first(static fn (LandingPage $page): bool => LandingType::Canton === $page->type && $page->slug === $slug)
            ?? $this->first(static fn (LandingPage $page): bool => LandingType::Building === $page->type && $page->slug === $slug);
    }

    public function byTown(string $cantonSlug, string $townSlug): ?LandingPage
    {
        return $this->first(static fn (LandingPage $page): bool => LandingType::Town === $page->type && $page->cantonSlug === $cantonSlug && $page->slug === $townSlug);
    }

    /**
     * Every canton, town and building type with at least one published venue, with its count,
     * for the back office's landing-page list (#26): the same statement as `all()` with the
     * threshold at 1, so there is one counting SQL. Not memoized and never touches the footer.
     *
     * @return list<LandingPage>
     */
    public function candidates(): array
    {
        return $this->load($this->statementFor(1));
    }

    public function statement(): SqlStatement
    {
        return $this->statementFor($this->minVenues);
    }

    public function reset(): void
    {
        $this->pages = null;
    }

    private function statementFor(int $min): SqlStatement
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());

        return new SqlStatement(
            <<<SQL
                {$published->with}
                SELECT 'canton' AS type, v.canton_code AS code, c.slug, c.slug AS canton_slug, v.canton_code AS canton, NULL AS town_name, count(*) AS venues
                FROM {$published->from} JOIN cantons c ON c.code = v.canton_code
                WHERE {$published->where}
                GROUP BY v.canton_code, c.slug
                HAVING count(*) >= :min
                UNION ALL
                SELECT 'town', v.town_id::text, tw.slug, c.slug, tw.canton_code, tw.name, count(*)
                FROM {$published->from} JOIN towns tw ON tw.id = v.town_id JOIN cantons c ON c.code = tw.canton_code
                WHERE {$published->where}
                GROUP BY v.town_id, tw.slug, c.slug, tw.canton_code, tw.name
                HAVING count(*) >= :min
                UNION ALL
                SELECT 'building', x.term_code, NULL, NULL, NULL, NULL, count(*)
                FROM {$published->from} JOIN venue_terms x ON x.venue_id = v.id AND x.term_group = 'building'
                WHERE {$published->where}
                GROUP BY x.term_code
                HAVING count(*) >= :min
                ORDER BY venues DESC, town_name, code
                SQL,
            $published->params + ['min' => $min],
            $published->types + ['min' => ParameterType::INTEGER],
        );
    }

    /**
     * @return list<LandingPage>
     */
    private function ofType(LandingType $type): array
    {
        return array_values(array_filter($this->all(), static fn (LandingPage $page): bool => $page->type === $type));
    }

    /**
     * @param callable(LandingPage): bool $match
     */
    private function first(callable $match): ?LandingPage
    {
        foreach ($this->all() as $page) {
            if ($match($page)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return list<LandingPage>
     */
    private function load(SqlStatement $statement): array
    {
        return array_map(function (array $row): LandingPage {
            \assert(\is_string($row['type']) && \is_string($row['code']) && \is_int($row['venues']));
            $type = LandingType::from($row['type']);
            $canton = \is_string($row['canton']) ? Canton::from($row['canton']) : null;
            $cantonSlug = \is_string($row['canton_slug']) ? $row['canton_slug'] : null;

            return match ($type) {
                LandingType::Canton => new LandingPage($type, (string) $cantonSlug, $row['venues'], $canton, $cantonSlug),
                LandingType::Town => new LandingPage($type, (string) $row['slug'], $row['venues'], $canton, $cantonSlug, $row['code'], (string) $row['town_name']),
                LandingType::Building => new LandingPage(
                    $type,
                    $this->translator->trans('landing.building.'.$row['code'].'.slug', [], 'search'),
                    $row['venues'],
                    building: $row['code'],
                ),
            };
        }, $this->connection->fetchAllAssociative($statement->sql, $statement->params, $statement->types));
    }
}
