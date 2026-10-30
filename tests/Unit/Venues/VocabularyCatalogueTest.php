<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\RatingLabel;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Every translatable Venues enum and the `venues` catalogue name the same values: the keys
 * are built at runtime (`<context>.<value>`), so debug:translation cannot see them.
 */
final class VocabularyCatalogueTest extends TestCase
{
    private const CATALOGUE = __DIR__.'/../../../translations/venues+intl-icu.de.yaml';

    /**
     * @return iterable<string, array{class-string<\BackedEnum&TranslatableInterface>, string}>
     */
    public static function enums(): iterable
    {
        yield 'TermGroup' => [TermGroup::class, 'term_group'];
        yield 'Tier' => [Tier::class, 'tier'];
        yield 'RatingLabel' => [RatingLabel::class, 'rating_label'];
        yield 'RatingHighlight' => [RatingHighlight::class, 'rating_highlight'];
        yield 'OwnCatering' => [OwnCatering::class, 'own_catering'];
        yield 'FloorMaterial' => [FloorMaterial::class, 'floor_material'];
        yield 'Seating' => [Seating::class, 'seating'];
        yield 'ReviewerRole' => [ReviewerRole::class, 'reviewer_role'];
        yield 'Access' => [Access::class, 'access'];
    }

    /**
     * @param class-string<\BackedEnum&TranslatableInterface> $enum
     */
    #[DataProvider('enums')]
    public function test_catalogue_has_one_label_per_case(string $enum, string $context): void
    {
        $values = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());

        self::assertSame($values, array_keys($this->labels($context)));
    }

    /**
     * @param class-string<\BackedEnum&TranslatableInterface> $enum
     */
    #[DataProvider('enums')]
    public function test_labels_are_non_empty_swiss_german(string $enum, string $context): void
    {
        foreach ($this->labels($context) as $value => $label) {
            self::assertIsString($label, $context.'.'.$value);
            self::assertNotSame('', trim($label), $context.'.'.$value);
            self::assertStringNotContainsString('ß', $label, $context.'.'.$value);
        }
    }

    /**
     * @param class-string<\BackedEnum&TranslatableInterface> $enum
     */
    #[DataProvider('enums')]
    public function test_cases_translate_through_the_venues_domain(string $enum, string $context): void
    {
        foreach ($enum::cases() as $case) {
            $translator = new RecordingTranslator();

            self::assertSame('label', $case->trans($translator, 'de'));
            self::assertSame([[$context.'.'.$case->value, [], 'venues', 'de']], $translator->calls);
        }
    }

    /**
     * @return array<mixed>
     */
    private function labels(string $context): array
    {
        $catalogue = Yaml::parseFile(self::CATALOGUE);
        self::assertIsArray($catalogue);
        self::assertArrayHasKey($context, $catalogue);
        self::assertIsArray($catalogue[$context]);

        return $catalogue[$context];
    }
}
