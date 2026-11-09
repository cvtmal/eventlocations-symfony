<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Content\ContentFolder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The event facts through app:venues:import (#31, event-vocabulary F3): every field reaches its
 * column, an update replaces it, a folder without the fields resets them, a bad value fails the
 * venue with the field's name, and the wedding-only fields of format version 1 are refused.
 */
final class ImportEventFactsTest extends KernelTestCase
{
    use RunsContentImport;

    private const array EVENT_GROUPS = ['amenity', 'catering', 'language'];

    public function test_round_trips_every_event_field(): void
    {
        $this->content->venue('schloss-fakten', self::facts());

        $this->assertImports();

        self::assertSame(
            ['medium', 120, 180, 'https://www.instagram.com/schloss.fakten'],
            array_values($this->factsOf('schloss-fakten')),
        );
        self::assertSame(
            ['ambience' => 'Festlich am See', 'capacity_note' => 'Mit Bühne 100 Plätze.', 'opening_hours' => 'Mai bis Oktober'],
            $this->texts('schloss-fakten'),
        );
        self::assertSame([
            'amenity.dance_floor', 'amenity.overnight',
            'catering.buffet', 'catering.vegan',
            'language.de', 'language.gsw',
        ], $this->terms('schloss-fakten'));
        self::assertSame(['corporate_event', 'seminar'], $this->connection->fetchFirstColumn(
            "SELECT term_code FROM venue_terms WHERE venue_id = ? AND term_group = 'suitable_for' ORDER BY term_code",
            [$this->venueId('schloss-fakten')],
        ));

        $this->assertImports();
        self::assertStringContainsString('Venues: 1 seen, 0 created, 0 updated, 1 unchanged', $this->display());
    }

    public function test_more_event_types_than_free_allows_are_kept_and_reported(): void
    {
        $types = ['corporate_event', 'seminar', 'conference', 'workshop', 'christmas_party'];
        $this->content->venue('schloss-viele', ['terms' => ['suitable_for' => $types]]);

        $this->assertImports();

        self::assertSame(5, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM venue_terms WHERE venue_id = ? AND term_group = 'suitable_for'",
            [$this->venueId('schloss-viele')],
        ), 'the import never trims (F12 follow-up: report)');
        self::assertStringContainsString('schloss-viele: event_types: 5 > 3 (Free)', $this->display());
    }

    public function test_an_update_replaces_the_facts(): void
    {
        $this->content->venue('schloss-fakten', self::facts());
        $this->assertImports();

        $this->content->change('schloss-fakten', [
            'price_level' => 'exclusive',
            'capacity' => ['seated' => 90],
            'contact' => ['instagram' => 'https://www.instagram.com/neu'],
            'terms' => ['catering' => ['in_house']],
            'translations' => ['de' => ['ambience' => 'Rustikal im Wald', 'capacity_note' => null]],
        ]);

        $this->assertImports();
        self::assertSame(
            ['exclusive', 90, 180, 'https://www.instagram.com/neu'],
            array_values($this->factsOf('schloss-fakten')),
        );
        self::assertSame(
            ['ambience' => 'Rustikal im Wald', 'capacity_note' => null, 'opening_hours' => 'Mai bis Oktober'],
            $this->texts('schloss-fakten'),
        );
        self::assertContains('catering.in_house', $this->terms('schloss-fakten'));
        self::assertNotContains('catering.buffet', $this->terms('schloss-fakten'));
    }

    public function test_removed_facts_reset_to_null(): void
    {
        $this->content->venue('schloss-fakten', self::facts());
        $this->assertImports();

        $this->content->edit('schloss-fakten', static fn (array $data): array => ContentFolder::minimal(\is_string($data['name']) ? $data['name'] : ''));

        $this->assertImports();
        self::assertSame(array_fill(0, 4, null), array_values($this->factsOf('schloss-fakten')));
        self::assertSame(['ambience' => null, 'capacity_note' => null, 'opening_hours' => null], $this->texts('schloss-fakten'));
        self::assertSame([], $this->terms('schloss-fakten'));
    }

    public function test_an_unknown_code_fails_loudly(): void
    {
        $this->content->venue('schloss-fakten', ['terms' => ['catering' => ['palace']]]);

        self::assertSame(Command::FAILURE, $this->import());
        self::assertStringContainsString('/terms/catering/0', $this->display());
        self::assertFalse($this->venueRow('schloss-fakten'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> the folder's overrides, the pointer the import reports
     */
    public static function retiredFacts(): iterable
    {
        yield 'the party section' => [['party' => ['allowed' => 'limited']], '/: Unknown properties: party'];
        yield 'the party note' => [['translations' => ['de' => ['party_note' => 'Musik bis 2 Uhr.']]], '/translations/de: Unknown properties: party_note'];
        yield 'the ceremony group' => [['terms' => ['ceremony' => ['chapel']]], '/terms: Unknown properties: ceremony'];
        yield 'the dining group' => [['terms' => ['dining' => ['vegan']]], '/terms: Unknown properties: dining'];
        yield 'the vendor group' => [['terms' => ['vendor' => ['dj']]], '/terms: Unknown properties: vendor'];
        yield 'the usage group' => [['terms' => ['usage' => ['exclusive']]], '/terms: Unknown properties: usage'];
        yield 'a building code that left with the wedding venue' => [['terms' => ['building' => ['wedding_venue']]], '/terms/building/0: "wedding_venue" is not an allowed value'];
        yield 'a code that moved from infrastructure to technology' => [['terms' => ['infrastructure' => ['wifi']]], '/terms/infrastructure/0: "wifi" is not an allowed value'];
        yield 'a retired event type' => [['terms' => ['suitable_for' => ['class_reunion']]], '/terms/suitable_for/0: "class_reunion" is not an allowed value'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('retiredFacts')]
    public function test_a_retired_wedding_fact_fails_the_venue(array $overrides, string $pointer): void
    {
        $this->content->venue('schloss-fakten', $overrides);

        self::assertSame(Command::FAILURE, $this->import());
        self::assertStringContainsString($pointer, $this->display());
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
            'contact' => ['instagram' => 'https://www.instagram.com/schloss.fakten'],
            'terms' => [
                'suitable_for' => ['corporate_event', 'seminar'],
                'amenity' => ['overnight', 'dance_floor'],
                'catering' => ['buffet', 'vegan'],
                'language' => ['de', 'gsw'],
            ],
            'translations' => ['de' => [
                'ambience' => 'Festlich am See',
                'capacity_note' => 'Mit Bühne 100 Plätze.',
                'opening_hours' => 'Mai bis Oktober',
            ]],
        ];
    }

    /**
     * @return array<string, mixed> the four venues columns
     */
    private function factsOf(string $key): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT price_level, capacity_seated, capacity_standing, instagram FROM venues WHERE content_key = ?',
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
            "SELECT ambience, capacity_note, opening_hours FROM venue_translations WHERE venue_id = ? AND locale = 'de'",
            [$this->venueId($key)],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string> "<group>.<code>" of the three event groups, sorted
     */
    private function terms(string $key): array
    {
        /** @var list<string> $terms */
        $terms = $this->connection->fetchFirstColumn(
            "SELECT term_group || '.' || term_code FROM venue_terms WHERE venue_id = ? AND term_group IN (?) ORDER BY 1",
            [$this->venueId($key), self::EVENT_GROUPS],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

        return $terms;
    }
}
