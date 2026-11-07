<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ProRequestTitle;
use App\Shared\Translation\EnabledLocale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The title of a Pro request's task (F12), from the shipped `crm` catalogue: the venue and the manager's address, within
 * the 200 characters of `crm_tasks.title`, the address kept when the venue's name has to give way.
 */
final class ProRequestTitleTest extends TestCase
{
    public function test_it_names_the_venue_and_the_address(): void
    {
        self::assertSame('Pro-Anfrage: Schloss Seeblick (anna@example.ch)', self::titles()->of('Schloss Seeblick', 'anna@example.ch'));
    }

    public function test_the_same_request_has_the_same_title(): void
    {
        self::assertSame(self::titles()->of('Villa Rosengarten', 'a@b.ch'), self::titles()->of('Villa Rosengarten', 'a@b.ch'));
    }

    public function test_a_title_at_the_limit_stays_whole(): void
    {
        // 13 characters before the venue and 3 around the address: 184 left, counted in characters, not bytes.
        $venue = str_repeat('ä', 184 - \strlen('anna@example.ch'));

        $title = self::titles()->of($venue, 'anna@example.ch');

        self::assertSame(ProRequestTitle::MAX, mb_strlen($title));
        self::assertSame('Pro-Anfrage: '.$venue.' (anna@example.ch)', $title);
    }

    public function test_a_long_venue_name_gives_way_and_the_address_stays(): void
    {
        // A venue's name holds 150 characters at most.
        $venue = substr(str_repeat('Seeblick ', 17), 0, 150);

        $title = self::titles()->of($venue, 'anna.meier.mit.langem.namen@restaurant-seeblick.example.ch');

        self::assertSame(ProRequestTitle::MAX, mb_strlen($title));
        self::assertStringStartsWith('Pro-Anfrage: Seeblick Seeblick', $title);
        self::assertStringEndsWith('… (anna.meier.mit.langem.namen@restaurant-seeblick.example.ch)', $title);
    }

    public function test_an_address_too_long_for_any_venue_cuts_the_whole_title(): void
    {
        $title = self::titles()->of('Schloss Seeblick', str_repeat('x', 190).'@example.ch');

        self::assertSame(ProRequestTitle::MAX, mb_strlen($title));
        self::assertStringStartsWith('Pro-Anfrage: …', $title);
        self::assertStringEndsWith('…', $title);
    }

    public function test_characters_that_icu_knows_in_a_name_are_plain_text(): void
    {
        self::assertSame("Pro-Anfrage: Gasthof 'Zum {Löwen}' (o'brien@example.ch)", self::titles()->of("Gasthof 'Zum {Löwen}'", "o'brien@example.ch"));
    }

    private static function titles(): ProRequestTitle
    {
        $translator = new Translator('de');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3).'/translations/crm+intl-icu.de.yaml', 'de', 'crm+intl-icu');

        return new ProRequestTitle($translator, new EnabledLocale(['de'], 'de'));
    }
}
