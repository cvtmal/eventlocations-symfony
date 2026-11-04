<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Content\ContentFolder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The owner facts of S14 through app:venues:import (#31): every field reaches its column, an
 * update replaces it, a folder without the fields resets them, and a bad value fails the
 * venue with the field's name.
 */
final class ImportWeddingFactsTest extends KernelTestCase
{
    use RunsContentImport;

    private const array SIX_GROUPS = ['amenity', 'ceremony', 'dining', 'language', 'usage', 'vendor'];

    public function test_round_trips_every_wedding_field(): void
    {
        $this->content->venue('schloss-fakten', self::facts());

        $this->assertImports();

        self::assertSame(
            ['medium', 120, 180, 'limited', true, '02:00', 'https://www.instagram.com/schloss.fakten'],
            array_values($this->factsOf('schloss-fakten')),
        );
        self::assertSame(
            ['ambience' => 'Romantisch am See', 'capacity_note' => 'Mit Tanzfläche 100 Plätze.', 'party_note' => 'Musik bis 2 Uhr.', 'opening_hours' => 'Mai bis Oktober'],
            $this->texts('schloss-fakten'),
        );
        self::assertSame([
            'amenity.dance_floor', 'amenity.overnight',
            'ceremony.free_ceremony', 'ceremony.rain_alternative',
            'dining.vegan', 'language.de', 'language.gsw', 'usage.exclusive', 'vendor.dj',
        ], $this->terms('schloss-fakten'));

        $this->assertImports();
        self::assertStringContainsString('Venues: 1 seen, 0 created, 0 updated, 1 unchanged', $this->display());
    }

    public function test_an_update_replaces_the_facts(): void
    {
        $this->content->venue('schloss-fakten', self::facts());
        $this->assertImports();

        $this->content->change('schloss-fakten', [
            'price_level' => 'exclusive',
            'capacity' => ['seated' => 90],
            'party' => ['allowed' => 'yes', 'has_curfew' => false, 'curfew_at' => null],
            'contact' => ['instagram' => 'https://www.instagram.com/neu'],
            'terms' => ['ceremony' => ['chapel']],
            'translations' => ['de' => ['ambience' => 'Rustikal im Wald', 'party_note' => null]],
        ]);

        $this->assertImports();
        self::assertSame(
            ['exclusive', 90, 180, 'yes', false, null, 'https://www.instagram.com/neu'],
            array_values($this->factsOf('schloss-fakten')),
        );
        self::assertSame(
            ['ambience' => 'Rustikal im Wald', 'capacity_note' => 'Mit Tanzfläche 100 Plätze.', 'party_note' => null, 'opening_hours' => 'Mai bis Oktober'],
            $this->texts('schloss-fakten'),
        );
        self::assertContains('ceremony.chapel', $this->terms('schloss-fakten'));
        self::assertNotContains('ceremony.free_ceremony', $this->terms('schloss-fakten'));
    }

    public function test_removed_facts_reset_to_null(): void
    {
        $this->content->venue('schloss-fakten', self::facts());
        $this->assertImports();

        $this->content->edit('schloss-fakten', static fn (array $data): array => ContentFolder::minimal(\is_string($data['name']) ? $data['name'] : ''));

        $this->assertImports();
        self::assertSame(array_fill(0, 7, null), array_values($this->factsOf('schloss-fakten')));
        self::assertSame(['ambience' => null, 'capacity_note' => null, 'party_note' => null, 'opening_hours' => null], $this->texts('schloss-fakten'));
        self::assertSame([], $this->terms('schloss-fakten'));
    }

    public function test_an_unknown_code_fails_loudly(): void
    {
        $this->content->venue('schloss-fakten', ['terms' => ['ceremony' => ['palace']]]);

        self::assertSame(Command::FAILURE, $this->import());
        self::assertStringContainsString('/terms/ceremony/0', $this->display());
        self::assertFalse($this->venueRow('schloss-fakten'));
    }

    public function test_a_curfew_time_without_a_curfew_fails(): void
    {
        $this->content->venue('schloss-fakten', ['party' => ['curfew_at' => '02:00']]);

        self::assertSame(Command::FAILURE, $this->import());
        self::assertStringContainsString('/party/curfew_at', $this->display());
        self::assertFalse($this->venueRow('schloss-fakten'));
    }

    private function assertImports(): void
    {
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(): array
    {
        return [
            'price_level' => 'medium',
            'capacity' => ['seated' => 120, 'standing' => 180],
            'party' => ['allowed' => 'limited', 'has_curfew' => true, 'curfew_at' => '02:00'],
            'contact' => ['instagram' => 'https://www.instagram.com/schloss.fakten'],
            'terms' => [
                'ceremony' => ['free_ceremony', 'rain_alternative'],
                'amenity' => ['overnight', 'dance_floor'],
                'dining' => ['vegan'],
                'vendor' => ['dj'],
                'usage' => ['exclusive'],
                'language' => ['de', 'gsw'],
            ],
            'translations' => ['de' => [
                'ambience' => 'Romantisch am See',
                'capacity_note' => 'Mit Tanzfläche 100 Plätze.',
                'party_note' => 'Musik bis 2 Uhr.',
                'opening_hours' => 'Mai bis Oktober',
            ]],
        ];
    }

    /**
     * @return array<string, mixed> the seven venues columns, curfew_at as HH:MM
     */
    private function factsOf(string $key): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT price_level, capacity_seated, capacity_standing, party, has_curfew, to_char(curfew_at, 'HH24:MI') AS curfew_at, instagram FROM venues WHERE content_key = ?",
            [$key],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function texts(string $key): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT ambience, capacity_note, party_note, opening_hours FROM venue_translations WHERE venue_id = ? AND locale = 'de'",
            [$this->venueId($key)],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string> "<group>.<code>" of the six groups, sorted
     */
    private function terms(string $key): array
    {
        /** @var list<string> $terms */
        $terms = $this->connection->fetchFirstColumn(
            "SELECT term_group || '.' || term_code FROM venue_terms WHERE venue_id = ? AND term_group IN (?) ORDER BY 1",
            [$this->venueId($key), self::SIX_GROUPS],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

        return $terms;
    }
}
