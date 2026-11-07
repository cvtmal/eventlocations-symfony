<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Cms\Form\ChangedFields;
use App\Cms\Form\VenueAddressData;
use App\Cms\Form\VenueBasicsData;
use App\Cms\Form\VenueEventData;
use App\Cms\Form\VenueLocaleTextsData;
use App\Cms\Form\VenueTermsData;
use App\Cms\Form\VenueTextsData;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PriceLevel;
use PHPUnit\Framework\TestCase;

final class ChangedFieldsTest extends TestCase
{
    public function test_equal_data_changes_nothing(): void
    {
        self::assertSame([], ChangedFields::between(self::basics(), self::basics()));
    }

    public function test_one_change_is_named(): void
    {
        $after = self::basics();
        $after->capacityMax = 250;

        self::assertSame(['capacityMax'], ChangedFields::between(self::basics(), $after));
    }

    public function test_an_enum_compares_by_value(): void
    {
        $before = new VenueEventData();
        $before->priceLevel = PriceLevel::Medium;
        $after = new VenueEventData();
        $after->priceLevel = PriceLevel::Exclusive;
        $same = new VenueEventData();
        $same->priceLevel = PriceLevel::Medium;

        self::assertSame(['priceLevel'], ChangedFields::between($before, $after));
        self::assertSame([], ChangedFields::between($before, $same));
    }

    public function test_a_tier_is_a_code_and_a_new_code_is_named(): void
    {
        $after = self::basics();
        $after->tier = KnownTiers::PRO;

        self::assertSame(['tier'], ChangedFields::between(self::basics(), $after));
        self::assertSame([], ChangedFields::between(self::basics(), self::basics()));
    }

    public function test_crlf_and_cr_equal_lf(): void
    {
        $before = self::texts("Erster Absatz.\n\nZweiter Absatz.");
        $after = self::texts("Erster Absatz.\r\n\r\nZweiter Absatz.");
        $mac = self::texts("Erster Absatz.\r\rZweiter Absatz.");

        self::assertSame([], ChangedFields::between($before, $after));
        self::assertSame([], ChangedFields::between($before, $mac));
    }

    public function test_leading_and_trailing_space_does_not_count(): void
    {
        $before = self::basics();
        $before->name = "Schloss Seeblick \u{3000}";
        $after = self::basics();
        $after->name = 'Schloss Seeblick';

        self::assertSame([], ChangedFields::between($before, $after));
    }

    public function test_an_empty_string_equals_null(): void
    {
        $before = self::texts('');
        $after = self::texts(null);

        self::assertSame([], ChangedFields::between($before, $after));
    }

    public function test_inner_whitespace_counts(): void
    {
        self::assertSame(['texts.de.description'], ChangedFields::between(self::texts('a b'), self::texts('a  b')));
    }

    public function test_reordered_chips_change_nothing(): void
    {
        $before = new VenueTermsData();
        $before->terms = ['building' => ['castle', 'barn'], 'setting' => []];
        $after = new VenueTermsData();
        $after->terms = ['building' => ['barn', 'castle'], 'setting' => []];

        self::assertSame([], ChangedFields::between($before, $after));

        $after->terms['building'][] = 'villa';
        self::assertSame(['terms.building'], ChangedFields::between($before, $after));
    }

    public function test_nested_locale_data_names_its_path(): void
    {
        $after = self::texts('Zwei Absätze.');
        $after->texts['de']->summary = 'Neu';

        self::assertSame(['texts.de.summary', 'texts.de.description'], ChangedFields::between(self::texts('Ein Absatz.'), $after));
    }

    public function test_an_empty_new_locale_changes_nothing(): void
    {
        $after = self::texts('Text');
        $after->texts['fr'] = new VenueLocaleTextsData();

        self::assertSame([], ChangedFields::between(self::texts('Text'), $after));

        $after->texts['fr']->summary = 'Résumé';
        self::assertSame(['texts.fr.summary'], ChangedFields::between(self::texts('Text'), $after));
    }

    public function test_the_token_and_the_button_are_ignored(): void
    {
        $before = new VenueAddressData();
        $before->loadedAt = '1700000000';
        $after = new VenueAddressData();
        $after->loadedAt = '1700000001';
        $after->useTownCentre = true;

        self::assertSame([], ChangedFields::between($before, $after));
    }

    private static function basics(): VenueBasicsData
    {
        $data = new VenueBasicsData();
        $data->loadedAt = '1700000000';
        $data->name = 'Schloss Seeblick';
        $data->slug = 'schloss-seeblick';
        $data->tier = KnownTiers::FREE;
        $data->capacityMin = 50;
        $data->capacityMax = 300;

        return $data;
    }

    private static function texts(?string $description): VenueTextsData
    {
        $locale = new VenueLocaleTextsData();
        $locale->summary = 'Kurztext';
        $locale->description = $description;
        $data = new VenueTextsData();
        $data->loadedAt = '1700000000';
        $data->texts['de'] = $locale;

        return $data;
    }
}
