<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\ValueObject\Canton;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Canton enum and the `geo` catalogue name the same 26 cantons.
 */
final class CantonCatalogueTest extends TestCase
{
    private const CATALOGUE = __DIR__.'/../../../translations/geo+intl-icu.de.yaml';

    public function test_catalogue_has_one_label_per_canton(): void
    {
        $labels = $this->cantonLabels();
        $codes = array_map(static fn (Canton $canton): string => $canton->value, Canton::cases());

        self::assertCount(26, Canton::cases());
        self::assertEqualsCanonicalizing($codes, array_keys($labels));
    }

    public function test_labels_are_non_empty_swiss_german(): void
    {
        foreach ($this->cantonLabels() as $code => $label) {
            self::assertIsString($label, $code);
            self::assertNotSame('', trim($label), $code);
            self::assertStringNotContainsString('ß', $label, $code);
        }
    }

    public function test_canton_translates_through_the_geo_domain(): void
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

                return 'St. Gallen';
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        self::assertSame('St. Gallen', Canton::SG->trans($translator, 'de'));
        self::assertSame([['canton.sg', [], 'geo', 'de']], $translator->calls);
        self::assertSame(Canton::ZH, Canton::from('zh'));
    }

    /**
     * @return array<mixed>
     */
    private function cantonLabels(): array
    {
        $catalogue = Yaml::parseFile(self::CATALOGUE);
        self::assertIsArray($catalogue);
        self::assertArrayHasKey('canton', $catalogue);
        self::assertIsArray($catalogue['canton']);

        return $catalogue['canton'];
    }
}
