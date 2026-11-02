<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Crm\CountsQueries;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Where a location's review shows on the account (#45): the way in, the overview rows, the location page's header.
 * The redirect is asserted without following it: step 1 links to #43's search, so it renders only once #43 lands.
 */
final class OnboardingOverviewTest extends WebTestCase
{
    use CountsQueries;
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    public function test_a_manager_without_any_row_lands_on_step_one(): void
    {
        $this->logInAsManager();

        $this->client->request('GET', '/konto');

        self::assertResponseRedirects('/konto/locations/neu');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Neue Location eintragen');
    }

    public function test_a_pending_manager_sees_the_overview(): void
    {
        PortalVenueAccessFactory::createOne(['user' => $this->logInAsManager()]);

        $this->client->request('GET', '/konto');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.empty__text');
    }

    public function test_the_overview_links_to_a_new_location(): void
    {
        PortalVenueAccessFactory::createOne(['user' => $this->logInAsManager()]);

        $crawler = $this->client->request('GET', '/konto');

        self::assertSame('Neue Location', $crawler->filter('a[href="/konto/locations/neu"]')->text());
    }

    public function test_a_draft_offers_weiter_einrichten(): void
    {
        $venue = $this->managed($this->logInAsManager());
        SubmissionFactory::createOne(['venue' => $venue]);

        $row = $this->client->request('GET', '/konto')->filter('.venue-widget__row');

        self::assertSame('Weiter einrichten', $row->filter('a[href="'.$this->wizard($venue).'"]')->text());
        self::assertNotContains('Abgelehnt', $this->pills($row));
        self::assertNotContains('Wird geprüft', $this->pills($row));
    }

    public function test_a_submitted_location_says_wird_geprueft(): void
    {
        $venue = $this->managed($this->logInAsManager());
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        $row = $this->client->request('GET', '/konto')->filter('.venue-widget__row');

        self::assertContains('Wird geprüft', $this->pills($row));
        self::assertCount(0, $row->filter('a[href="'.$this->wizard($venue).'"]'));
    }

    public function test_a_rejected_location_offers_weiter_einrichten(): void
    {
        $venue = $this->managed($this->logInAsManager());
        SubmissionFactory::new()->rejected()->create(['venue' => $venue]);

        $row = $this->client->request('GET', '/konto')->filter('.venue-widget__row');

        self::assertContains('Abgelehnt', $this->pills($row));
        self::assertSame('Weiter einrichten', $row->filter('a[href="'.$this->wizard($venue).'"]')->text());
    }

    public function test_a_published_location_shows_no_review_state(): void
    {
        $manager = $this->logInAsManager();
        SubmissionFactory::new()->accepted()->create(['venue' => $this->managed($manager, VenueStatus::Published)]);
        // Offline again after an accepted review: staff's old yes says nothing about the location today.
        SubmissionFactory::new()->accepted()->create(['venue' => $this->managed($manager)]);
        // Published with an open row: the row is ignored as long as the location is online.
        SubmissionFactory::new()->submitted()->create(['venue' => $this->managed($manager, VenueStatus::Published)]);

        $rows = $this->client->request('GET', '/konto')->filter('.venue-widget__row');

        self::assertCount(3, $rows);
        self::assertCount(0, $rows->filter('a[href$="/einrichten"]'));
        self::assertNotContains('Wird geprüft', $this->pills($rows));
        self::assertNotContains('Abgelehnt', $this->pills($rows));
    }

    public function test_the_statuses_add_one_statement(): void
    {
        $manager = $this->logInAsManager();
        SubmissionFactory::createOne(['venue' => $this->managed($manager)]);
        SubmissionFactory::new()->submitted()->create(['venue' => $this->managed($manager)]);

        $this->queriesOf('/konto');

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $reads = array_filter($holder->getData()['default'] ?? [], static fn (array $query): bool => str_contains((string) $query['sql'], 'portal_submissions'));
        self::assertCount(1, $reads);
    }

    public function test_an_offline_location_links_to_wieder_veroeffentlichen(): void
    {
        $venue = $this->managed($this->logInAsManager());
        SubmissionFactory::new()->accepted()->create(['venue' => $venue]);

        $crawler = $this->client->request('GET', $this->location($venue));

        self::assertSame('Wieder veröffentlichen', $crawler->filter('.record-header a[href="'.$this->wizard($venue).'/pruefen"]')->text());
        self::assertCount(0, $crawler->filter('.record-header a[href="'.$this->wizard($venue).'"]'));
    }

    public function test_a_claimed_unpublished_location_links_to_it_too(): void
    {
        $venue = $this->managed($this->logInAsManager());

        foreach (['', '/fotos'] as $tab) {
            $crawler = $this->client->request('GET', $this->location($venue).$tab);

            self::assertSame('Wieder veröffentlichen', $crawler->filter('.record-header a[href="'.$this->wizard($venue).'/pruefen"]')->text());
        }
    }

    public function test_a_published_location_shows_neither(): void
    {
        $venue = $this->managed($this->logInAsManager(), VenueStatus::Published);
        SubmissionFactory::new()->accepted()->create(['venue' => $venue]);

        $crawler = $this->client->request('GET', $this->location($venue));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.record-header a[href*="/einrichten"]'));
        self::assertStringNotContainsString('Wird geprüft', $crawler->filter('.record-header')->text());
    }

    private function managed(PortalUser $manager, VenueStatus $status = VenueStatus::Unpublished): Venue
    {
        $venue = VenueFactory::createOne(['status' => $status]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    private function location(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number();
    }

    private function wizard(Venue $venue): string
    {
        return $this->location($venue).'/einrichten';
    }

    /**
     * @return list<string>
     */
    private function pills(Crawler $rows): array
    {
        return $rows->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text());
    }
}
