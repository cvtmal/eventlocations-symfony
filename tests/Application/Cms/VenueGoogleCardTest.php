<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Test\Factories;

/**
 * The card "Google-Bewertung" (#53, D4, D6): staff set the place ID and, once one is saved, the switch that hides
 * the rating. A save is audited and purges the venue page, but never stamps `cms_edited_at` (no import can
 * overwrite the place ID).
 */
final class VenueGoogleCardTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    private const string PLACE_ID = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    public function test_saving_a_place_id_stores_audits_and_purges_without_marking(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $this->save($venue, ['placeId' => ' '.self::PLACE_ID.' ']);

        self::assertResponseRedirects($this->url($venue).'#google-bewertung');
        self::assertSame([self::PLACE_ID, false, null], array_values((array) $this->connection->fetchAssociative('SELECT google_place_id, google_rating_hidden, cms_edited_at FROM venues WHERE id = ?', [$venue->id()])));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
        $audit = $this->connection->fetchAssociative("SELECT command, changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
        self::assertIsArray($audit);
        self::assertSame(ChangeVenueGooglePlace::class, $audit['command']);
        self::assertSame(['changed' => ['placeId']], json_decode((string) $audit['changes'], true));

        $crawler = $this->client->followRedirect();
        self::assertSame('«Google-Bewertung» gespeichert.', $crawler->filter('.toast')->text());
        self::assertSame(self::PLACE_ID, $crawler->filter('#venue_google_placeId')->attr('value'));
        self::assertStringContainsString('hat die Google-Bewertung geändert', $crawler->filter('section[aria-labelledby="venue-history"]')->text());
    }

    public function test_the_switch_and_the_check_link_appear_only_with_a_saved_id(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->editor($venue);

        self::assertCount(0, $crawler->filter('#venue_google_ratingHidden'));
        self::assertCount(0, $crawler->filter('#google-bewertung a[target="_blank"]'));
        self::assertStringContainsString('sobald eine Place ID gespeichert ist', $crawler->filter('#google-bewertung')->text());

        $this->withPlace($venue);
        $crawler = $this->editor($venue);

        self::assertCount(1, $crawler->filter('#venue_google_ratingHidden'));
        self::assertNull($crawler->filter('#venue_google_ratingHidden')->attr('checked'));
        $link = $crawler->filter('#google-bewertung a[target="_blank"]');
        self::assertSame('Auf Google Maps prüfen', $link->text());
        self::assertSame('https://www.google.com/maps/search/?api=1&query='.rawurlencode($venue->name()).'&query_place_id='.self::PLACE_ID, $link->attr('href'));
        self::assertSame('noopener', $link->attr('rel'));
    }

    public function test_ticking_the_switch_hides_the_rating(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->withPlace($venue);

        $crawler = $this->editor($venue);
        $form = $crawler->filter('form[name="venue_google"]')->form();
        $switch = $form['venue_google[ratingHidden]'];
        self::assertInstanceOf(ChoiceFormField::class, $switch);
        $switch->tick();
        $this->client->submit($form);

        self::assertResponseRedirects($this->url($venue).'#google-bewertung');
        self::assertTrue($this->connection->fetchOne('SELECT google_rating_hidden FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame('checked', $this->client->followRedirect()->filter('#venue_google_ratingHidden')->attr('checked'));
    }

    public function test_clearing_the_id_clears_the_switch(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->withPlace($venue, hidden: true);

        $this->save($venue, ['placeId' => '']);

        self::assertResponseRedirects($this->url($venue).'#google-bewertung');
        self::assertSame([null, false], array_values((array) $this->connection->fetchAssociative('SELECT google_place_id, google_rating_hidden FROM venues WHERE id = ?', [$venue->id()])));
    }

    public function test_an_invalid_id_is_a_field_error(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->save($venue, ['placeId' => 'ChIJ mit Leerzeichen']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#venue_google_placeId')->attr('aria-invalid'));
        self::assertStringContainsString('Bitte eine gültige Place ID eingeben', $crawler->filter('#google-bewertung')->text());
        self::assertNull($this->connection->fetchOne('SELECT google_place_id FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_stale_save_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->editor($venue);
        $form = $crawler->filter('form[name="venue_google"]')->form();
        $this->connection->executeStatement("UPDATE venues SET updated_at = updated_at + INTERVAL '1 hour' WHERE id = ?", [$venue->id()]);
        $crawler = $this->client->submit($form, ['venue_google[placeId]' => self::PLACE_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(self::PLACE_ID, $crawler->filter('#venue_google_placeId')->attr('value'));
        self::assertCount(1, $crawler->filter('#google-bewertung .venue-card__stale'));
        self::assertNull($this->connection->fetchOne('SELECT google_place_id FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_the_card_is_for_staff_only(): void
    {
        $venue = DetailVenues::minimal();

        $this->client->request('POST', $this->url($venue).'/google-bewertung');
        self::assertResponseRedirects('/admin/login');

        $this->logInAs(AdminRole::Editor);
        $crawler = $this->editor($venue);
        self::assertSame('Google-Bewertung', $crawler->filter('#venue-card-google')->text());
    }

    private function withPlace(Venue $venue, bool $hidden = false): void
    {
        $this->connection->executeStatement('UPDATE venues SET google_place_id = ?, google_rating_hidden = ? WHERE id = ?', [self::PLACE_ID, $hidden ? 'true' : 'false', $venue->id()]);
    }

    private function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number();
    }

    private function editor(Venue $venue): Crawler
    {
        $crawler = $this->client->request('GET', $this->url($venue));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param array<string, string> $values
     */
    private function save(Venue $venue, array $values): Crawler
    {
        $form = $this->editor($venue)->filter('form[name="venue_google"]')->form();
        foreach ($values as $field => $value) {
            $form['venue_google['.$field.']'] = $value;
        }

        return $this->client->submit($form);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
