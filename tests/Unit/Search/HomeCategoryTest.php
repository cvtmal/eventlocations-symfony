<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\ValueObject\HomeCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The HomeCategory enum, its catalogue keys and its icons agree; one tile per event type (F5).
 */
final class HomeCategoryTest extends TestCase
{
    private const CATALOGUE = __DIR__.'/../../../translations/search+intl-icu.de.yaml';

    public function test_six_event_types_in_order(): void
    {
        self::assertSame(
            ['corporate_event', 'seminar', 'apero', 'christmas_party', 'birthday', 'wedding'],
            array_map(static fn (HomeCategory $category): string => $category->value, HomeCategory::cases()),
        );
    }

    public function test_the_catalogue_has_one_label_per_case(): void
    {
        $catalogue = Yaml::parseFile(self::CATALOGUE);
        self::assertIsArray($catalogue);
        self::assertIsArray($catalogue['home'] ?? null);
        self::assertIsArray($catalogue['home']['category'] ?? null);

        $labels = $catalogue['home']['category'];
        $values = array_map(static fn (HomeCategory $category): string => $category->value, HomeCategory::cases());
        self::assertEqualsCanonicalizing($values, array_keys($labels));
        foreach ($labels as $key => $label) {
            self::assertIsString($label, (string) $key);
            self::assertNotSame('', trim($label), (string) $key);
        }
    }

    public function test_every_case_names_its_icon(): void
    {
        self::assertSame(
            ['briefcase', 'presentation', 'wine', 'gift', 'cake', 'heart'],
            array_map(static fn (HomeCategory $category): string => $category->icon(), HomeCategory::cases()),
        );
    }

    public function test_a_category_translates_through_the_search_domain(): void
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

                return 'Apéro';
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        self::assertSame('Apéro', HomeCategory::Apero->trans($translator, 'de'));
        self::assertSame([['home.category.apero', [], 'search', 'de']], $translator->calls);
    }
}
