<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Geo\ValueObject\Canton;
use App\Venues\ContentFormat;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The enums of docs/content-format.schema.json equal the PHP enums and the taxonomy_terms
 * rows, in the same order, so the content format cannot drift from the vocabulary.
 */
final class ContentSchemaVocabularyTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<\BackedEnum>}>
     */
    public static function enums(): iterable
    {
        yield 'status' => ['properties.status.enum', VenueStatus::class];
        yield 'own catering' => ['properties.faq.properties.own_catering.enum', OwnCatering::class];
        yield 'floor material' => ['properties.rooms.items.properties.floor_material.enum', FloorMaterial::class];
        yield 'seating' => ['properties.rooms.items.properties.seating.propertyNames.enum', Seating::class];
        yield 'price level' => ['properties.price_level.enum', PriceLevel::class];
        yield 'canton' => ['properties.address.properties.canton.enum', Canton::class];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('enums')]
    public function test_schema_enum_lists_the_php_enum_values(string $path, string $enum): void
    {
        $values = array_values(array_filter((array) self::at($path), static fn (mixed $value): bool => null !== $value));

        self::assertSame(array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()), $values);
    }

    public function test_term_groups_and_codes_equal_taxonomy_terms(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $terms = self::at('properties.terms.properties');
        self::assertInstanceOf(\stdClass::class, $terms);

        self::assertSame(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), array_keys(get_object_vars($terms)));
        foreach (TermGroup::cases() as $group) {
            $codes = $connection->fetchFirstColumn('SELECT code FROM taxonomy_terms WHERE term_group = ? ORDER BY position', [$group->value]);

            self::assertSame($codes, self::at('properties.terms.properties.'.$group->value.'.items.enum'), $group->value);
        }
        foreach (['setting', 'building', 'suitable_for'] as $group) {
            self::assertSame(self::at('properties.terms.properties.'.$group.'.items.enum'), self::at('properties.main_terms.properties.'.$group.'.enum'), $group);
        }
        $main = self::at('properties.main_terms.properties');
        self::assertInstanceOf(\stdClass::class, $main);
        self::assertSame(['setting', 'building', 'suitable_for'], array_keys(get_object_vars($main)));
    }

    public function test_the_schema_has_no_tier(): void
    {
        $properties = self::at('properties');
        self::assertInstanceOf(\stdClass::class, $properties);

        self::assertArrayNotHasKey('tier', get_object_vars($properties));
        self::assertNotContains('tier', (array) self::at('required'));
    }

    public function test_key_patterns_equal_the_php_pattern(): void
    {
        self::assertSame(trim(ContentFormat::KEY_PATTERN, '/'), self::at('properties.rooms.items.properties.key.pattern'));
    }

    private static function at(string $path): mixed
    {
        $node = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/'.ContentFormat::SCHEMA_FILE), flags: \JSON_THROW_ON_ERROR);
        foreach (explode('.', $path) as $segment) {
            \assert($node instanceof \stdClass);
            $node = $node->{$segment};
        }

        return $node;
    }
}
