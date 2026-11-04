<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Content\WixCsv;
use App\Venues\Exception\InvalidWixExport;
use App\Venues\LegacyVenueMapper;
use App\Venues\ValueObject\Content\LegacyVenue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LegacyVenueMapperTest extends TestCase
{
    public function test_a_complete_row(): void
    {
        $venue = self::map([]);

        self::assertSame('1a2b3c4d', $venue->id8);
        self::assertSame('Landgasthof Beispielhof', $venue->name);
        self::assertSame(120, $venue->capacityMax);
        self::assertSame('zh', $venue->cantonCode);
        self::assertNull($venue->region);
        self::assertSame(WixCsv::PHONE_E164, $venue->phone);
        self::assertSame(WixCsv::EMAIL, $venue->inquiryEmail);
        self::assertSame(WixCsv::WEBSITE, $venue->website);
        self::assertSame(WixCsv::DESCRIPTION, $venue->description);
        self::assertSame(WixCsv::DESCRIPTION, $venue->summary);
        self::assertSame([1, 'whole'], [$venue->descriptionRule, $venue->summarySource]);
        self::assertSame([], $venue->notes);
    }

    public function test_every_german_canton_label_maps_to_its_code(): void
    {
        $catalogue = Yaml::parseFile(\dirname(__DIR__, 3).'/translations/geo+intl-icu.de.yaml');
        \assert(\is_array($catalogue) && \is_array($catalogue['canton']));
        self::assertCount(27, $catalogue['canton']);

        foreach ($catalogue['canton'] as $code => $label) {
            self::assertSame($code, self::map(['Region' => (string) $label])->cantonCode, (string) $label);
        }
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function regions(): iterable
    {
        yield 'alias Fribourg' => ['Fribourg', 'fr', null];
        yield 'alias Rümlang: canton, no town' => ['Rümlang', 'zh', null];
        yield 'outer whitespace' => [' Bern ', 'be', null];
        yield 'decomposed label' => ["Graubu\u{0308}nden", 'gr', null];
        yield 'known text without a canton' => ['Schweizweit', null, 'Schweizweit'];
        yield 'Basel is not guessed' => ['Basel', null, 'Basel'];
        yield 'a new label is kept' => ['Emmental und Umgebung', null, 'Emmental und Umgebung'];
    }

    #[DataProvider('regions')]
    public function test_region(string $region, ?string $canton, ?string $text): void
    {
        $venue = self::map(['Region' => $region]);

        self::assertSame([$canton, $text], [$venue->cantonCode, $venue->region]);
    }

    public function test_liechtenstein_maps_to_li_and_is_mapped_like_any_row(): void
    {
        $venue = self::map(['Region' => ' Liechtenstein ', 'Name' => 'Schloss Beispiel ']);

        self::assertSame(['li', null], [$venue->cantonCode, $venue->region]);
        self::assertSame('Schloss Beispiel', $venue->name);
        self::assertSame([], $venue->notes);
    }

    public function test_an_empty_region_gives_neither_and_a_note(): void
    {
        $venue = self::map(['Region' => '']);

        self::assertSame([null, null], [$venue->cantonCode, $venue->region]);
        self::assertSame(['no Region'], $venue->notes);
    }

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function phones(): iterable
    {
        yield 'national' => ['0441234567', '+41441234567', false];
        yield 'with spaces' => ['044 123 45 67', '+41441234567', false];
        yield 'quoted international' => ["'+41441234567", '+41441234567', false];
        yield 'empty' => ['', null, false];
        yield 'too short' => ['365', null, true];
        yield 'Liechtenstein' => ['+4233001122', '+4233001122', false];
        yield 'Liechtenstein with 00 and spaces' => ['00423 300 11 22', '+4233001122', false];
        yield 'Liechtenstein too long' => ['+42330011223', null, true];
        yield 'foreign' => ['+4930123456', null, true];
    }

    #[DataProvider('phones')]
    public function test_phone(string $input, ?string $phone, bool $dropped): void
    {
        $venue = self::map(['Telefon' => $input]);

        self::assertSame($phone, $venue->phone);
        self::assertSame($dropped ? [LegacyVenueMapper::NOTE_PHONE_DROPPED] : [], $venue->notes);
    }

    public function test_email_and_website_are_trimmed_and_otherwise_verbatim(): void
    {
        $venue = self::map(['Email' => ' Info@Beispielhof.example.ch ', 'Website' => " https://beispielhöf.example.ch\n"]);

        self::assertSame('Info@Beispielhof.example.ch', $venue->inquiryEmail);
        self::assertSame('https://beispielhöf.example.ch', $venue->website);
    }

    public function test_a_bad_email_or_website_is_dropped_and_noted_without_the_value(): void
    {
        $venue = self::map(['Email' => 'keine adresse', 'Website' => 'www.beispielhof.example.ch']);

        self::assertNull($venue->inquiryEmail);
        self::assertNull($venue->website);
        self::assertSame([LegacyVenueMapper::NOTE_EMAIL_DROPPED, LegacyVenueMapper::NOTE_WEBSITE_DROPPED], $venue->notes);
    }

    public function test_the_name_is_composed_and_collapsed(): void
    {
        self::assertSame('Scheune Grün', self::map(['Name' => "  Scheune   Gru\u{0308}n "])->name);
    }

    public function test_the_capacity_accepts_the_singular_and_outer_whitespace(): void
    {
        self::assertSame(1, self::map(['Kapazität' => ' 1 Person '])->capacityMax);
    }

    public function test_a_row_without_a_description_gets_the_ambience_and_notes(): void
    {
        $venue = self::map(['Vorstellungstext' => WixCsv::richText(WixCsv::paragraph('Einrichtung'), WixCsv::paragraph('Tische'))]);

        self::assertNull($venue->description);
        self::assertSame('Ländlich', $venue->summary);
        self::assertSame([4, 'ambience'], [$venue->descriptionRule, $venue->summarySource]);
        self::assertSame(['no description', 'summary from Ambiente'], $venue->notes);
    }

    public function test_contact_like_text_is_kept_and_flagged_without_quoting_it(): void
    {
        $text = 'Rufen Sie uns an unter 044 987 65 43 oder schreiben Sie an gast@beispielhof.example.ch für alle Fragen.';
        $venue = self::map(['Vorstellungstext' => WixCsv::richText(WixCsv::paragraph('Beschrieb'), WixCsv::paragraph($text))]);

        self::assertSame($text, $venue->description);
        self::assertSame(['the text contains something like an address or a phone number'], $venue->notes);
    }

    public function test_an_ambience_summary_of_exactly_200_characters_passes(): void
    {
        $venue = self::map(['Vorstellungstext' => WixCsv::richText(), 'Ambiente' => str_repeat('a', 200)]);

        self::assertSame(200, mb_strlen($venue->summary));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function unresolved(): iterable
    {
        yield 'capacity without a number' => [['Kapazität' => 'auf Anfrage'], 'Kapazität'];
        yield 'capacity zero' => [['Kapazität' => '0 Personen'], 'Kapazität'];
        yield 'capacity above the limit' => [['Kapazität' => '50001 Personen'], 'Kapazität'];
        yield 'empty name' => [['Name' => '  '], 'Name'];
        yield 'long name' => [['Name' => str_repeat('a', 151)], 'Name'];
        yield 'no slug' => [['Name' => '…'], 'URL slug'];
        yield 'bad id' => [['ID' => 'XYZ'], 'ID'];
        yield 'invalid rich text' => [['Vorstellungstext' => '{kein json'], 'rich text'];
        yield 'no text at all' => [['Vorstellungstext' => WixCsv::richText(), 'Ambiente' => ''], 'Ambiente'];
        yield 'long region' => [['Region' => str_repeat('a', 61)], 'Region'];
        yield 'an Ambiente summary above 200 characters' => [['Vorstellungstext' => WixCsv::richText(), 'Ambiente' => str_repeat('a', 201)], 'summary from the Ambiente'];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('unresolved')]
    public function test_a_row_that_cannot_be_a_venue_is_unresolved(array $overrides, string $reason): void
    {
        $this->expectException(InvalidWixExport::class);
        $this->expectExceptionMessage($reason);

        self::map($overrides);
    }

    /**
     * @param array<string, string> $overrides
     */
    private static function map(array $overrides): LegacyVenue
    {
        return (new LegacyVenueMapper())->map(WixCsv::row($overrides));
    }
}
