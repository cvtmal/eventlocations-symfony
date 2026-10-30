<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\ReadModel\PlaceLookup;
use App\Geo\ReadModel\PlaceSuggestion;
use App\Geo\ValueObject\PlaceType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlaceLookupTest extends KernelTestCase
{
    use ImportsTownDataset;

    private PlaceLookup $lookup;

    protected function setUp(): void
    {
        // Not wired anywhere yet (#6 adds the endpoint), so the container has removed the service.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->lookup = new PlaceLookup($connection);
    }

    public function test_zuer_suggests_canton_zurich_then_the_town(): void
    {
        $suggestions = $this->lookup->suggest('zuer');

        self::assertSame(PlaceType::Canton, $suggestions[0]->type);
        self::assertSame('zh', $suggestions[0]->cantonCode);
        self::assertSame(PlaceType::Town, $suggestions[1]->type);
        self::assertSame('Zürich', $suggestions[1]->name);
    }

    public function test_exact_zip_comes_first(): void
    {
        $first = $this->lookup->suggest('8712')[0];

        self::assertSame('Stäfa', $first->name);
        self::assertSame('8712', $first->zip);
        self::assertSame('zh', $first->cantonCode);
        self::assertNotNull($first->townId);
    }

    public function test_zip_of_a_town_with_one_postcode(): void
    {
        self::assertSame('Jona', $this->lookup->suggest('8645')[0]->name);
    }

    public function test_zip_prefix_lists_each_town_once(): void
    {
        $suggestions = $this->lookup->suggest('80');
        $ids = array_map(static fn (PlaceSuggestion $s): ?string => $s->townId, $suggestions);

        self::assertNotEmpty($suggestions);
        self::assertLessThanOrEqual(8, \count($suggestions));
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertSame('Zürich', $suggestions[0]->name);
    }

    public function test_rapperswil_suggests_rapperswil_sg_first(): void
    {
        self::assertSame('Rapperswil SG', $this->firstTown('rapperswil')->name);
    }

    public function test_jona_suggests_jona_first(): void
    {
        self::assertSame('Jona', $this->firstTown('jona')->name);
    }

    #[DataProvider('zurichSpellings')]
    public function test_spelling_variants_find_zurich(string $q): void
    {
        self::assertSame('Zürich', $this->firstTown($q)->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zurichSpellings(): iterable
    {
        yield 'accent-free' => ['Zurich'];
        yield 'German transliteration' => ['Zuerich'];
        yield 'umlaut, lower case' => ['zürich'];
        yield 'typo' => ['zurch'];
    }

    public function test_punctuation_is_folded(): void
    {
        self::assertSame('St. Gallen', $this->firstTown('St.Gallen')->name);
    }

    public function test_ge_suggests_canton_geneva_and_town_geneve(): void
    {
        $suggestions = $this->lookup->suggest('ge');
        $cantons = array_map(
            static fn (PlaceSuggestion $s): string => $s->cantonCode,
            array_filter($suggestions, static fn (PlaceSuggestion $s): bool => PlaceType::Canton === $s->type),
        );

        self::assertContains('ge', $cantons);
        self::assertSame('Genève', $this->firstTown('ge')->name);
    }

    public function test_at_most_eight_results(): void
    {
        self::assertLessThanOrEqual(8, \count($this->lookup->suggest('a')));
        self::assertCount(8, $this->lookup->suggest('a'));
    }

    #[DataProvider('emptyInputs')]
    public function test_input_without_letters_or_digits_gives_nothing(string $q): void
    {
        self::assertSame([], $this->lookup->suggest($q));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'punctuation' => ['--'];
    }

    public function test_canton_suggestion_carries_only_its_code(): void
    {
        $canton = $this->lookup->suggest('zuer')[0];

        self::assertSame(PlaceType::Canton, $canton->type);
        self::assertNull($canton->townId);
        self::assertNull($canton->name);
        self::assertNull($canton->zip);
    }

    private function firstTown(string $q): PlaceSuggestion
    {
        foreach ($this->lookup->suggest($q) as $suggestion) {
            if (PlaceType::Town === $suggestion->type) {
                return $suggestion;
            }
        }
        self::fail(\sprintf('No town suggested for "%s".', $q));
    }
}
