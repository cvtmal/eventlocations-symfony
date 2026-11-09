<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Public copy, mails and `/konto` address the reader with "du" and use Swiss spelling (E4); `lint:translations` checks
 * ICU syntax only. Formal forms are refused case-sensitively, so third-person `sie`/`ihr` in lower case passes; a
 * capitalised `Sie` or `Ihr` at a sentence start is refused too, so reword it (the plural `ihr` counts only next to a
 * second-person verb). Every catalogue except the staff ones is public and found by glob, so a new catalogue is covered
 * without a list. Twig, PHP and JavaScript are read as text, because a string written into them skips the catalogues; no
 * folder is left out, since staff copy is informal too. The staff catalogues hold prose that may say "she" or "they" with
 * a capital, so they are checked for a formal form inside a sentence only.
 */
final class PublicCopyAddressTest extends TestCase
{
    /** The back office: informal copy. */
    private const array STAFF = ['admin', 'cms', 'crm'];

    private const string FORMAL = '/\b(Sie|Ihnen|Ihr|Ihre|Ihrem|Ihren|Ihrer|Ihres|Sehr geehrte[nrms]?)\b/u';

    private const string PLURAL = '/\b([Ee]uch|[Ee]uer|[Ee]ur(e|en|em|er|es)|([Kk]önnt|[Hh]abt|[Ww]ollt|[Ss]eid|[Mm]üsst|[Dd]ürft|[Ss]ollt|[Ww]erdet) ihr|ihr (könnt|habt|wollt|seid|müsst|dürft|sollt|werdet))\b/u';

    private const string ESZETT = '/ß/u';

    /** After a lower-case letter, a comma or a semicolon, so "Entfernen Sie" counts and a sentence-start "Sie bleibt" does not. */
    private const string FORMAL_IN_SENTENCE = '/(?<=[\p{Ll},;] )(Sie|Ihnen|Ihre[mnrs]?|Ihr)\b/u';

    /** The catalogues that speak to the reader, so each has at least one du-form. */
    private const array ADDRESSED = ['favorites', 'inquiries', 'messages', 'portal', 'search', 'seo', 'shared', 'stats', 'styleguide', 'validators'];

    /** `(?!-)` keeps the brand `deine-eventlocation.ch` from counting. */
    private const string DU = '/\b([Dd]u|[Dd]ich|[Dd]ir|[Dd]ein(e|en|em|er|es)?)\b(?!-)/u';

    public function test_public_catalogues_use_du_and_swiss_spelling(): void
    {
        $domains = $this->publicDomains();
        self::assertNotEmpty($domains, 'no public catalogue found');

        $hits = [];
        foreach ($domains as $domain) {
            foreach (self::strings($this->publicValues($domain)) as $value) {
                foreach ([self::FORMAL, self::PLURAL, self::ESZETT] as $pattern) {
                    if (1 === preg_match($pattern, $value)) {
                        $hits[] = "{$domain}: {$value}";
                    }
                }
            }
        }

        self::assertSame([], $hits);
    }

    public function test_addressed_catalogues_contain_du(): void
    {
        foreach (self::ADDRESSED as $domain) {
            $found = false;
            foreach (self::strings($this->publicValues($domain)) as $value) {
                $found = $found || 1 === preg_match(self::DU, $value);
            }

            self::assertTrue($found, "{$domain} has no du-form");
        }
    }

    public function test_templates_and_code_use_du_and_swiss_spelling(): void
    {
        // PHP and JavaScript may hold an `ß` on purpose, in a map that turns it into "ss", so only the address forms count there.
        $hits = [
            ...self::hits('templates', '*.twig', [self::FORMAL, self::PLURAL, self::ESZETT]),
            ...self::hits('src', '*.php', [self::FORMAL, self::PLURAL]),
            ...self::hits('assets/js', '*.js', [self::FORMAL, self::PLURAL]),
            // nginx's static page while PHP is down (F16 N8); depth 0 keeps the media and build folders out.
            ...self::hits('public', '*.html', [self::FORMAL, self::PLURAL, self::ESZETT], 0),
        ];

        self::assertSame([], $hits);
    }

    public function test_staff_catalogues_do_not_address_the_reader_formally(): void
    {
        $groups = [];
        foreach (self::STAFF as $domain) {
            $groups[$domain] = $this->values($domain);
        }
        $groups['validators (staff keys)'] = array_intersect_key($this->values('validators'), array_flip(['cms', 'crm']));

        $hits = [];
        foreach ($groups as $group => $values) {
            foreach (self::strings($values) as $value) {
                if (1 === preg_match(self::FORMAL_IN_SENTENCE, $value)) {
                    $hits[] = "{$group}: {$value}";
                }
            }
        }

        self::assertSame([], $hits);
    }

    #[DataProvider('copy')]
    public function test_the_check_tells_formal_from_informal_copy(string $text, bool $refused): void
    {
        self::assertSame($refused, 1 === preg_match(self::FORMAL, $text) || 1 === preg_match(self::PLURAL, $text) || 1 === preg_match(self::ESZETT, $text), $text);
    }

    /**
     * One trigger per row, so dropping a form from a pattern fails exactly its row.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function copy(): iterable
    {
        yield 'Sie' => ['Bitte geben Sie die Daten ein.', true];
        yield 'Sie at a sentence start' => ['Sie können das Formular absenden.', true];
        yield 'Ihnen' => ['Wir antworten Ihnen direkt.', true];
        yield 'Ihr' => ['Ihr Konto ist bereit.', true];
        yield 'Ihre' => ['Danke für Ihre Anfrage.', true];
        yield 'Ihrem' => ['Eine Kopie liegt in Ihrem Postfach.', true];
        yield 'Ihren' => ['Wir prüfen Ihren Eintrag.', true];
        yield 'Ihrer' => ['Das ist die Seite Ihrer Location.', true];
        yield 'Ihres' => ['Der Name Ihres Anlasses.', true];
        yield 'Sehr geehrte' => ['Sehr geehrte Damen und Herren', true];
        yield 'euch' => ['Wir melden uns bei euch.', true];
        yield 'euer' => ['Das ist euer Fest.', true];
        yield 'eure' => ['Wir lieben eure Ideen.', true];
        yield 'eurem' => ['Wir schreiben an eurem Team.', true];
        yield 'euren' => ['Wir kennen euren Anlass.', true];
        yield 'eurer' => ['Das ist die Seite eurer Location.', true];
        yield 'eures' => ['Der Name eures Anlasses.', true];
        foreach (['könnt', 'habt', 'wollt', 'seid', 'müsst', 'dürft', 'sollt', 'werdet'] as $verb) {
            yield "{$verb} ihr" => [ucfirst($verb).' ihr uns helfen?', true];
            yield "ihr {$verb}" => ["Wenn ihr {$verb}, melden wir uns.", true];
        }
        yield 'eszett' => ['Das Schloss ist groß.', true];

        yield 'du' => ['Du kannst deine Merkliste jederzeit öffnen.', false];
        yield 'dir' => ['Wir melden uns bei dir.', false];
        yield 'greeting' => ['Hallo Anna', false];
        yield 'she or they' => ['Frag die Location, ob sie den Termin frei hat.', false];
        yield 'her or their' => ['Die Location und ihre Räume, mit ihr reden, ihr Team fragen.', false];
        yield 'swiss spelling' => ['Das Schloss ist gross.', false];
        yield 'the brand' => ['Willkommen bei deine-eventlocation.ch', false];
    }

    #[DataProvider('staffCopy')]
    public function test_the_staff_check_skips_a_sentence_start(string $text, bool $refused): void
    {
        self::assertSame($refused, 1 === preg_match(self::FORMAL_IN_SENTENCE, $text), $text);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function staffCopy(): iterable
    {
        yield 'imperative' => ['Entfernen Sie zuerst ein Foto.', true];
        yield 'clause' => ['Wenn Sie fertig sind, speichern.', true];
        yield 'dative' => ['Das geht Ihnen nichts an.', true];
        yield 'possessive' => ['Bitte Ihre Daten prüfen.', true];

        yield 'she at the start' => ['Sie bleibt unpubliziert, bis ein Foto da ist.', false];
        yield 'its at a sentence start' => ['Gelöscht, samt ihren Kontakten. Ihr Eintrag wird frei.', false];
        yield 'they after a placeholder' => ['Platzhalter: {fields}. Sie werden beim Auswählen ausgefüllt.', false];
        yield 'informal' => ['Entferne zuerst ein Foto.', false];
    }

    #[DataProvider('duCopy')]
    public function test_the_du_check_finds_a_du_form_and_skips_the_brand(string $text, bool $found): void
    {
        self::assertSame($found, 1 === preg_match(self::DU, $text), $text);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function duCopy(): iterable
    {
        yield 'du' => ['Du kannst es sehen.', true];
        yield 'dich' => ['Wir fragen dich.', true];
        yield 'dir' => ['Wir antworten dir.', true];
        yield 'dein' => ['Dein Konto', true];
        yield 'deine' => ['Deine Merkliste', true];
        yield 'deinen' => ['Wir kennen deinen Anlass.', true];
        yield 'deinem' => ['In deinem Postfach.', true];
        yield 'deiner' => ['Auf deiner Seite.', true];
        yield 'deines' => ['Der Name deines Anlasses.', true];

        yield 'the brand' => ['Willkommen bei deine-eventlocation.ch', false];
        yield 'formal' => ['Wir antworten Ihnen direkt.', false];
        yield 'a word that starts with du' => ['Eine dunkle Dusche.', false];
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function hits(string $dir, string $name, array $patterns, ?int $depth = null): array
    {
        $files = (new Finder())->files()->in(self::root().'/'.$dir)->name($name);
        if (null !== $depth) {
            $files->depth($depth);
        }
        self::assertGreaterThan(0, $files->count(), "no {$name} file found in {$dir}");

        $hits = [];
        foreach ($files as $file) {
            foreach ($patterns as $pattern) {
                if (1 === preg_match($pattern, $file->getContents(), $match)) {
                    $hits[] = "{$dir}/{$file->getRelativePathname()}: {$match[0]}";
                }
            }
        }

        return $hits;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        array_walk_recursive($values, static function (mixed $value) use (&$strings): void {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        });

        return $strings;
    }

    /**
     * @return list<string>
     */
    private function publicDomains(): array
    {
        $domains = [];
        foreach ((new Finder())->files()->in(self::root().'/translations')->name('*+intl-icu.de.yaml') as $file) {
            $domain = explode('+', $file->getFilename())[0];
            if (!\in_array($domain, self::STAFF, true)) {
                $domains[] = $domain;
            }
        }
        sort($domains);

        return $domains;
    }

    /**
     * @return array<mixed>
     */
    private function values(string $domain): array
    {
        return (array) Yaml::parseFile(self::root()."/translations/{$domain}+intl-icu.de.yaml");
    }

    /**
     * @return array<mixed>
     */
    private function publicValues(string $domain): array
    {
        $values = $this->values($domain);
        if ('validators' === $domain) {
            unset($values['cms'], $values['crm']);
        }

        return $values;
    }
}
