<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Step 1 of the wizard (#45): the form, the hint on a possible duplicate, and what `CreateOwnLocation` refuses.
 */
final class NewLocationTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    private const string URL = '/konto/locations/neu';
    private const string NAME = 'Seehaus Meilen';
    private const string TOWN = '8706 Meilen';

    public function test_a_valid_form_without_matches_creates_and_goes_to_texte(): void
    {
        $manager = $this->logInAsManager();

        $this->post();

        $number = $this->connection->fetchOne('SELECT number FROM venues WHERE name = ?', [self::NAME]);
        self::assertResponseRedirects('/konto/locations/'.$number.'/einrichten/texte', Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->managedDrafts($manager));

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('Schritt 2 von 6', $crawler->filter('.progress__label')->text());

        $this->client->request('GET', '/konto/locations/'.$number.'/einrichten');
        self::assertResponseRedirects('/konto/locations/'.$number.'/einrichten/texte');
    }

    public function test_trotzdem_neu_anlegen_creates(): void
    {
        $manager = $this->logInAsManager();
        DetailVenues::minimal('seehaus-meilen', self::NAME);

        $this->post(['confirmed' => '1']);

        self::assertSame(Response::HTTP_SEE_OTHER, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->venues());
        self::assertSame(1, $this->managedDrafts($manager));
    }

    public function test_the_form_has_no_tier_field(): void
    {
        $this->logInAsManager();

        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="new_location"] input[name="new_location[name]"]'));
        self::assertCount(0, $crawler->filter('[name="new_location[tier]"]'));
    }

    public function test_possible_matches_show_and_nothing_is_written(): void
    {
        $this->logInAsManager();
        DetailVenues::minimal('seehaus-meilen', self::NAME);

        $crawler = $this->submitForm();

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString(self::NAME, $crawler->filter('#new-location-matches')->text());
        self::assertSame(self::NAME, $crawler->filter('#new_location_name')->attr('value'));
        self::assertCount(1, $crawler->filter('button[name="confirmed"]'));
        self::assertSame(1, $this->venues());
    }

    public function test_the_match_offers_zugriff_anfragen_to_the_claims_route(): void
    {
        $this->logInAsManager();
        $venue = DetailVenues::minimal('seehaus-meilen', self::NAME);

        $crawler = $this->submitForm();

        $ask = $crawler->filter('#new-location-matches form');
        self::assertSame('/konto/locations/zugriff', $ask->attr('action'));
        self::assertSame((string) $venue->number(), $ask->filter('input[name="number"]')->attr('value'));
        self::assertSame(self::NAME, $ask->filter('input[name="q"]')->attr('value'));
        self::assertSame('Zugriff anfragen', $ask->filter('button')->text());
    }

    public function test_only_published_locations_match(): void
    {
        $this->logInAsManager();
        VenueFactory::createOne(['name' => self::NAME, 'status' => VenueStatus::Unpublished]);

        $this->submitForm();

        self::assertSame(Response::HTTP_SEE_OTHER, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->venues());
    }

    public function test_an_unknown_town_is_a_field_error_with_422(): void
    {
        $this->logInAsManager();

        $crawler = $this->submitForm(['town' => '9999 Nirgendwo']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Diesen Ort kennen wir nicht', $crawler->filter('form[name="new_location"]')->text());
        self::assertSame(0, $this->venues());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedInput(): iterable
    {
        yield 'no name' => [['name' => ''], 'Bitte'];
        yield 'a name without letters' => [['name' => '!!'], 'Buchstaben'];
        yield 'no town' => [['town' => ''], 'Bitte geben Sie PLZ und Ort an.'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('refusedInput')]
    public function test_a_too_short_name_or_missing_town_is_422(array $input, string $message): void
    {
        $this->logInAsManager();

        $crawler = $this->submitForm($input);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString($message, $crawler->filter('form[name="new_location"]')->text());
        self::assertSame(0, $this->venues());
    }

    public function test_the_eleventh_location_is_refused_with_the_plain_message(): void
    {
        $manager = $this->logInAsManager();
        for ($i = 0; $i < 10; ++$i) {
            $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
            SubmissionFactory::createOne(['venue' => $venue]);
            PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        }

        $crawler = $this->submitForm();

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Sie haben bereits 10 unfertige Locations. Schliessen Sie eine davon ab oder schreiben Sie uns.', $crawler->filter('form[name="new_location"]')->text());
        self::assertSame(10, $this->venues());
    }

    public function test_the_page_links_to_the_search(): void
    {
        $this->logInAsManager();

        $crawler = $this->client->request('GET', self::URL);

        self::assertCount(1, $crawler->filter('a[href="/konto/locations/suchen"]'));
    }

    /**
     * @param array<string, string> $input
     */
    private function submitForm(array $input = []): Crawler
    {
        $form = $this->client->request('GET', self::URL)->filter('form[name="new_location"]')->form();

        $values = array_replace(['name' => self::NAME, 'capacityMax' => '120', 'town' => self::TOWN], $input);

        return $this->client->submit($form, array_combine(array_map(static fn (string $field): string => 'new_location['.$field.']', array_keys($values)), $values));
    }

    /**
     * Posts without rendering step 1. The profile page gives the POST its same-origin Referer and a form token.
     *
     * @param array<string, string> $body
     */
    private function post(array $body = []): void
    {
        $token = $this->client->request('GET', '/konto/profil')->filter('input[name$="[_token]"]')->attr('value');
        $this->client->request('POST', self::URL, ['new_location' => ['name' => self::NAME, 'capacityMax' => '120', 'town' => self::TOWN, '_token' => $token]] + $body);
    }

    private function venues(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venues');
    }

    private function managedDrafts(PortalUser $manager): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM portal_submissions s
                JOIN portal_venue_access a ON a.venue_id = s.venue_id
                WHERE a.portal_user_id = ? AND a.status = 'active' AND s.status = 'draft'
                SQL,
            [$manager->id()],
        );
    }
}
