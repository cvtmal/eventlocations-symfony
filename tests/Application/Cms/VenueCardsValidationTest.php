<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FormField;
use Zenstruck\Foundry\Test\Factories;

/**
 * Every venue CHECK a card can reach, and the length limits of D17, refused by the form before the database
 * sees it: 422, the field marked invalid, nothing stored. One success row per card proves the value lands.
 */
final class VenueCardsValidationTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    /**
     * @return iterable<string, array{string, array<string, string>, string}> form name, values, selector of the marked field
     */
    public static function refused(): iterable
    {
        $long = static fn (int $length): string => str_repeat('a', $length);

        yield 'capacity max 0' => ['venue_basics', ['capacityMax' => '0'], '#venue_basics_capacityMax'];
        yield 'capacity max 50001' => ['venue_basics', ['capacityMax' => '50001'], '#venue_basics_capacityMax'];
        yield 'capacity min 0' => ['venue_basics', ['capacityMin' => '0'], '#venue_basics_capacityMin'];
        yield 'capacity min above max' => ['venue_basics', ['capacityMin' => '400'], '#venue_basics_capacityMax'];
        yield 'name too long' => ['venue_basics', ['name' => $long(151)], '#venue_basics_name'];
        yield 'slug with capitals' => ['venue_basics', ['slug' => 'Schloss-Seeblick'], '#venue_basics_slug'];
        yield 'slug too long' => ['venue_basics', ['slug' => $long(161)], '#venue_basics_slug'];

        yield 'summary too long' => ['venue_texts', ['texts][de][summary' => $long(201)], '#venue_texts_texts_de_summary'];
        yield 'meta description too long' => ['venue_texts', ['texts][de][metaDescription' => $long(161)], '#venue_texts_texts_de_metaDescription'];
        yield 'parking text too long' => ['venue_texts', ['texts][de][parkingText' => $long(251)], '#venue_texts_texts_de_parkingText'];

        yield 'town without a ZIP' => ['venue_address', ['town' => 'Meilen'], '#venue_address_town'];
        yield 'five-digit ZIP' => ['venue_address', ['town' => '87061 Meilen'], '#venue_address_town'];
        yield 'street without a town' => ['venue_address', ['town' => ''], '#venue_address_street'];
        yield 'street too long' => ['venue_address', ['street' => $long(151)], '#venue_address_street'];
        yield 'latitude outside Switzerland' => ['venue_address', ['lat' => '48.5'], '#venue_address_lat'];
        yield 'longitude outside Switzerland' => ['venue_address', ['lng' => '11.2'], '#venue_address_lng'];
        yield 'latitude alone' => ['venue_address', ['lng' => ''], '#venue_address_lng'];
        yield 'latitude not a number' => ['venue_address', ['lat' => '47,3x'], '#venue_address_lat'];
        yield 'distance above 500 km' => ['venue_address', ['stationKm' => '501'], '#venue_address_stationKm'];
        yield 'distance with two decimals' => ['venue_address', ['highwayKm' => '3.25'], '#venue_address_highwayKm'];
        yield 'distance name without km' => ['venue_address', ['stationKm' => ''], '#venue_address_stationKm'];
        yield 'distance name too long' => ['venue_address', ['airportName' => $long(151)], '#venue_address_airportName'];

        yield 'seated capacity 0' => ['venue_event', ['capacitySeated' => '0'], '#venue_event_capacitySeated'];
        yield 'standing capacity 50001' => ['venue_event', ['capacityStanding' => '50001'], '#venue_event_capacityStanding'];
        yield 'unknown price level' => ['venue_event', ['priceLevel' => 'cheap'], '#venue_event_priceLevel_error'];
        yield 'ambience too long' => ['venue_event', ['texts][de][ambience' => $long(61)], '#venue_event_texts_de_ambience'];
        yield 'capacity note too long' => ['venue_event', ['texts][de][capacityNote' => $long(251)], '#venue_event_texts_de_capacityNote'];
        yield 'opening hours too long' => ['venue_event', ['texts][de][openingHours' => $long(251)], '#venue_event_texts_de_openingHours'];

        yield 'phone of letters' => ['venue_contact', ['phone' => 'abc'], '#venue_contact_phone'];
        yield 'e-mail without a dot in the domain' => ['venue_contact', ['inquiryEmail' => 'info@seeblick'], '#venue_contact_inquiryEmail'];
        yield 'website without http' => ['venue_contact', ['website' => 'ftp://seeblick.example'], '#venue_contact_website'];
        yield 'instagram naming a website' => ['venue_contact', ['instagram' => 'https://www.seeblick.example'], '#venue_contact_instagram'];
        yield 'instagram post, not a profile' => ['venue_contact', ['instagram' => 'https://www.instagram.com/p/C0abc123/'], '#venue_contact_instagram'];
        yield 'instagram handle with a space' => ['venue_contact', ['instagram' => '@schloss seeblick'], '#venue_contact_instagram'];
        yield 'instagram handle of 31 characters' => ['venue_contact', ['instagram' => str_repeat('a', 31)], '#venue_contact_instagram'];
        yield 'contact person too long' => ['venue_contact', ['contactPerson' => $long(151)], '#venue_contact_contactPerson'];

        yield 'unknown daylight answer' => ['venue_faq', ['daylight' => 'sometimes'], '#venue_faq_daylight_error'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('refused')]
    public function test_a_value_the_database_would_refuse_is_a_field_error(string $formName, array $values, string $selector): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();
        $before = $this->snapshot($venue);

        $crawler = $this->post($venue, $formName, $values);

        self::assertResponseStatusCodeSame(422);
        $marked = $crawler->filter($selector);
        self::assertCount(1, $marked, $selector);
        if (!str_ends_with($selector, '_error')) {
            self::assertSame('true', $marked->attr('aria-invalid'), $selector);
        }
        self::assertSame($before, $this->snapshot($venue));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, string, string}> form name, values, SQL of the stored value, expected, field shown
     */
    public static function stored(): iterable
    {
        yield 'basics' => ['venue_basics', ['capacityMax' => '350'], 'SELECT capacity_max::text FROM venues WHERE id = ?', '350', '#venue_basics_capacityMax'];
        yield 'texts' => ['venue_texts', ['texts][de][summary' => 'Ein Schloss am Zürichsee.'], "SELECT summary FROM venue_translations WHERE venue_id = ? AND locale = 'de'", 'Ein Schloss am Zürichsee.', '#venue_texts_texts_de_summary'];
        yield 'address' => ['venue_address', ['stationKm' => '1,4'], 'SELECT distance_station_km::text FROM venues WHERE id = ?', '1.4', '#venue_address_stationKm'];
        yield 'terms' => ['venue_terms', ['mainBuilding' => ''], "SELECT COUNT(*)::text FROM venue_terms WHERE venue_id = ? AND term_group = 'building' AND is_main", '0', '#venue_terms_mainBuilding'];
        yield 'event' => ['venue_event', ['capacitySeated' => '1100'], 'SELECT capacity_seated::text FROM venues WHERE id = ?', '1100', '#venue_event_capacitySeated'];
        yield 'contact' => ['venue_contact', ['website' => 'https://neu.seeblick.example'], 'SELECT website FROM venues WHERE id = ?', 'https://neu.seeblick.example', '#venue_contact_website'];
        yield 'faq' => ['venue_faq', ['daylight' => 'no'], 'SELECT daylight::text FROM venues WHERE id = ?', 'false', '#venue_faq_daylight_1'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('stored')]
    public function test_a_valid_value_is_stored_and_shown(string $formName, array $values, string $sql, string $expected, string $selector): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();

        $this->post($venue, $formName, $values);

        self::assertResponseRedirects();
        self::assertSame($expected, $this->connection->fetchOne($sql, [$venue->id()]));
        $crawler = $this->client->followRedirect();
        $field = $crawler->filter($selector);
        self::assertCount(1, $field, $selector);
        $shown = match ($field->nodeName()) {
            'textarea' => $field->text(),
            'select' => $field->filter('option[selected]')->count() > 0 ? (string) $field->filter('option[selected]')->attr('value') : '',
            default => 'radio' === $field->attr('type') ? (string) $field->attr('checked') : (string) $field->attr('value'),
        };
        self::assertSame(match ($formName) {
            'venue_address' => '1.4',
            'venue_terms' => '',
            'venue_faq' => 'checked',
            default => array_values($values)[0],
        }, $shown);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function instagramInputs(): iterable
    {
        yield 'handle' => ['remise.rebhalde'];
        yield '@handle' => ['@remise.rebhalde'];
        yield 'profile URL' => ['https://www.instagram.com/remise.rebhalde/?hl=de'];
        yield 'profile URL over http, without www' => ['http://instagram.com/remise.rebhalde'];
    }

    /** The card stores every accepted shape as the one profile link, and shows that link back (#45 F-m3). */
    #[DataProvider('instagramInputs')]
    public function test_an_instagram_handle_or_url_is_stored_as_the_profile_link(string $input): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();

        $this->post($venue, 'venue_contact', ['instagram' => $input]);

        self::assertResponseRedirects();
        self::assertSame('https://www.instagram.com/remise.rebhalde', $this->connection->fetchOne('SELECT instagram FROM venues WHERE id = ?', [$venue->id()]));
        $crawler = $this->client->followRedirect();
        self::assertSame('https://www.instagram.com/remise.rebhalde', $crawler->filter('#venue_contact_instagram')->attr('value'));
    }

    public function test_an_emptied_instagram_is_removed(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();

        $this->post($venue, 'venue_contact', ['instagram' => ' ']);

        self::assertResponseRedirects();
        self::assertNull($this->connection->fetchOne('SELECT instagram FROM venues WHERE id = ?', [$venue->id()]));
    }

    /**
     * The old `UrlType` stored a dotted handle typed without `@` as `https://remise.rebhalde`, which names no profile.
     * Sent back untouched it stays as stored and blocks no other field; a readable old shape shows as the profile link
     * (small-ux-followups F-M1).
     */
    public function test_an_untouched_unreadable_instagram_does_not_block_the_card(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();
        $this->connection->executeStatement("UPDATE venues SET instagram = 'https://remise.rebhalde' WHERE id = ?", [$venue->id()]);

        $this->post($venue, 'venue_contact', ['contactPerson' => 'Beat Muster']);

        self::assertResponseRedirects();
        self::assertSame(['Beat Muster', 'https://remise.rebhalde'], $this->connection->fetchNumeric('SELECT contact_person, instagram FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_a_readable_old_instagram_shows_as_the_profile_link(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue();
        $this->connection->executeStatement("UPDATE venues SET instagram = 'https://@remiserebhalde' WHERE id = ?", [$venue->id()]);

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertSame('https://www.instagram.com/remiserebhalde', $crawler->filter('#venue_contact_instagram')->attr('value'));
    }

    private function venue(): Venue
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $venue = DetailVenues::full($em);
        $em->flush();

        return $venue;
    }

    /**
     * Opens the editor, changes the named fields of one card's form and submits it, as a browser would; values
     * the browser could not pick (an unknown choice) go through as typed.
     *
     * @param array<string, string> $values field paths below the form name, `texts][de][summary` for nested ones
     */
    private function post(Venue $venue, string $formName, array $values): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());
        $form = $crawler->filter('form[name="'.$formName.'"]')->form();
        $form->disableValidation();
        foreach ($values as $path => $value) {
            $field = $form[$formName.'['.$path.']'];
            self::assertInstanceOf(FormField::class, $field);
            $field->setValue($value);
        }

        return $this->client->submit($form);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Venue $venue): array
    {
        return [
            'venue' => $this->connection->fetchAssociative('SELECT * FROM venues WHERE id = ?', [$venue->id()]),
            'translations' => $this->connection->fetchAllAssociative('SELECT * FROM venue_translations WHERE venue_id = ? ORDER BY locale', [$venue->id()]),
            'terms' => $this->connection->fetchAllAssociative('SELECT * FROM venue_terms WHERE venue_id = ? ORDER BY term_group, term_code', [$venue->id()]),
            'audit' => $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]),
        ];
    }
}
