<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\ValueObject\GuestBand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The GuestBand enum (#51): its order, how a count buckets, its bounds on capacity_max and its catalogue keys.
 */
final class GuestBandTest extends TestCase
{
    private const CATALOGUE = __DIR__.'/../../../translations/search+intl-icu.de.yaml';

    public function test_four_cases_in_ascending_order(): void
    {
        self::assertSame(
            [50, 100, 150, 151],
            array_map(static fn (GuestBand $band): int => $band->value, GuestBand::cases()),
        );
    }

    /**
     * @return iterable<string, array{int, GuestBand}>
     */
    public static function counts(): iterable
    {
        yield '1' => [1, GuestBand::UpTo50];
        yield '50' => [50, GuestBand::UpTo50];
        yield '51' => [51, GuestBand::UpTo100];
        yield '100' => [100, GuestBand::UpTo100];
        yield '101' => [101, GuestBand::UpTo150];
        yield '150' => [150, GuestBand::UpTo150];
        yield '151' => [151, GuestBand::Over150];
        yield '50000' => [50000, GuestBand::Over150];
    }

    #[DataProvider('counts')]
    public function test_from_guests_buckets_a_count(int $guests, GuestBand $expected): void
    {
        self::assertSame($expected, GuestBand::fromGuests($guests));
    }

    public function test_min_and_max(): void
    {
        self::assertSame(
            [[1, 50], [51, 100], [101, 150], [151, null]],
            array_map(static fn (GuestBand $band): array => [$band->min(), $band->max()], GuestBand::cases()),
        );
    }

    public function test_the_catalogue_has_one_label_per_case(): void
    {
        $catalogue = Yaml::parseFile(self::CATALOGUE);
        self::assertIsArray($catalogue);
        self::assertIsArray($catalogue['filter'] ?? null);
        self::assertIsArray($catalogue['filter']['guests_band'] ?? null);

        $labels = $catalogue['filter']['guests_band'];
        $values = array_map(static fn (GuestBand $band): int => $band->value, GuestBand::cases());
        self::assertEqualsCanonicalizing($values, array_keys($labels));
        foreach ($labels as $key => $label) {
            self::assertIsString($label, (string) $key);
            self::assertNotSame('', trim($label), (string) $key);
        }
    }

    public function test_a_band_translates_through_the_search_domain(): void
    {
        $translator = new class implements TranslatorInterface {
            /** @var list<array{string, array<mixed>, ?string, ?string}> */
            public array $calls = [];

            /**
             * @param array<mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $this->calls[] = [$id, $parameters, $domain, $locale];

                return 'über 150 Gäste';
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        self::assertSame('über 150 Gäste', GuestBand::Over150->trans($translator, 'de'));
        self::assertSame([['filter.guests_band.151', [], 'search', 'de']], $translator->calls);
    }
}
