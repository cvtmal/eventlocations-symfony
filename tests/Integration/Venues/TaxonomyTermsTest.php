<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The 147 taxonomy_terms rows the migrations seed: counts, order, the 11 primary buildings,
 * and a catalogue label for every row (and a row for every label).
 */
final class TaxonomyTermsTest extends KernelTestCase
{
    private const CATALOGUE = __DIR__.'/../../../translations/venues+intl-icu.de.yaml';

    private const COUNTS = [
        'setting' => 5,
        'building' => 39,
        'suitable_for' => 21,
        'infrastructure' => 9,
        'furniture' => 5,
        'technology' => 5,
        'surroundings' => 6,
        'ceremony' => 6,
        'amenity' => 15,
        'dining' => 13,
        'vendor' => 8,
        'usage' => 3,
        'language' => 12,
    ];

    private const PRIMARY_BUILDINGS = [
        'bar_club', 'event_venue', 'wedding_venue', 'hotel', 'party_room', 'restaurant',
        'castle', 'congress_centre', 'forest_hut', 'farm_barn', 'mountain_hut',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function test_every_group_has_its_terms_at_positions_one_to_n(): void
    {
        self::assertSame(147, (int) $this->connection->fetchOne('SELECT count(*) FROM taxonomy_terms'));
        self::assertSame(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), array_keys(self::COUNTS));

        foreach (self::COUNTS as $group => $count) {
            $positions = $this->connection->fetchFirstColumn('SELECT position FROM taxonomy_terms WHERE term_group = ? ORDER BY position', [$group]);

            self::assertSame(range(1, $count), array_map('intval', $positions), $group);
        }
    }

    public function test_the_eleven_primary_buildings_in_chip_order(): void
    {
        $primaries = $this->connection->fetchAllKeyValue('SELECT code, primary_position FROM taxonomy_terms WHERE primary_position IS NOT NULL ORDER BY primary_position');

        self::assertSame(self::PRIMARY_BUILDINGS, array_keys($primaries));
        self::assertSame(range(1, 11), array_map('intval', array_values($primaries)));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM taxonomy_terms WHERE primary_position IS NOT NULL AND term_group <> 'building'"));
    }

    public function test_wedding_comes_first_in_suitable_for(): void
    {
        self::assertSame('wedding', $this->connection->fetchOne("SELECT code FROM taxonomy_terms WHERE term_group = 'suitable_for' AND position = 1"));
    }

    public function test_every_row_has_a_label_and_every_label_a_row(): void
    {
        $rows = array_map(
            static fn (array $row): string => $row['term_group'].'.'.$row['code'],
            $this->connection->fetchAllAssociative('SELECT term_group, code FROM taxonomy_terms'),
        );

        $catalogue = Yaml::parseFile(self::CATALOGUE);
        self::assertIsArray($catalogue);
        self::assertIsArray($catalogue['term']);
        $labels = [];
        foreach ($catalogue['term'] as $group => $terms) {
            self::assertIsArray($terms);
            foreach ($terms as $code => $label) {
                self::assertIsString($label);
                self::assertNotSame('', trim($label));
                $labels[] = $group.'.'.$code;
            }
        }

        self::assertEqualsCanonicalizing($rows, $labels);
    }

    public function test_repository_returns_a_group_in_position_order(): void
    {
        $repository = self::getContainer()->get(TaxonomyTermRepository::class);
        self::assertInstanceOf(TaxonomyTermRepository::class, $repository);

        $settings = $repository->byGroup(TermGroup::Setting);

        self::assertSame(['river', 'lake', 'countryside', 'mountains', 'city'], array_map(static fn ($term): string => $term->code(), $settings));
        self::assertSame(TermGroup::Setting, $settings[0]->termGroup());
        self::assertSame('term.setting.river', 'term.'.$settings[0]->term()->group->value.'.'.$settings[0]->term()->code);
        self::assertCount(39, $repository->byGroup(TermGroup::Building));
    }
}
