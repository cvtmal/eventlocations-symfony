<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * G12: the editor's "Suchmaschinen" card shows what keeps an entry out of the index, and staff check or withdraw
 * the facts with one audited button; the public page follows (noindex, sitemap).
 */
final class VenueIndexingTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    private const string CARD = 'section[aria-labelledby="venue-indexing"]';

    public function test_staff_check_the_facts_and_the_entry_is_indexed(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue('haus-ungeprueft', unchecked: true);
        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        $card = $crawler->filter(self::CARD);
        self::assertStringContainsString('Online, aber noindex', $card->text());
        self::assertCount(1, $card->filter('.venue-checklist__item--done'), 'the description is written, the facts are not checked');
        self::assertStringContainsString('noindex', $this->publicRobots('haus-ungeprueft'));
        self::assertStringNotContainsString('/eventlokal/haus-ungeprueft', $this->sitemap());

        $this->purger()->venues = [];
        $this->purger()->listings = 0;
        $this->client->submit($crawler->filter('form[name="check_facts"]')->form());

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#venue-indexing');
        $crawler = $this->client->followRedirect();
        self::assertSame('Die Fakten von «Haus Ungeprüft» sind als geprüft markiert.', $crawler->filter('.toast')->text());
        self::assertStringContainsString('Wird indexiert', $crawler->filter(self::CARD)->text());
        self::assertCount(1, $crawler->filter('form[name="uncheck_facts"]'));
        self::assertNotNull($this->connection->fetchOne('SELECT facts_checked_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]), 'outside the content format');
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings, 'landing pages count indexable entries');
        self::assertSame(1, $this->auditRows($venue->number()));
        self::assertStringContainsString('die Prüfung der Fakten geändert', $crawler->filter('section[aria-labelledby="venue-history"]')->text());
        self::assertStringNotContainsString('noindex', $this->publicRobots('haus-ungeprueft'));
        self::assertStringContainsString('/eventlokal/haus-ungeprueft', $this->sitemap());

        $this->client->submit($crawler->filter('form[name="uncheck_facts"]')->form());

        self::assertSame('Die Prüfung von «Haus Ungeprüft» ist zurückgenommen.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertNull($this->connection->fetchOne('SELECT facts_checked_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertStringContainsString('noindex', $this->publicRobots('haus-ungeprueft'));
    }

    public function test_an_entry_without_a_description_stays_out_of_the_index_although_checked(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue('haus-ohne-text', description: null);

        $card = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter(self::CARD);

        self::assertCount(1, $card->filter('.venue-checklist__item--done'), 'checked, but no description');
        self::assertCount(1, $card->filter('form[name="uncheck_facts"]'));
        self::assertStringContainsString('noindex', $this->publicRobots('haus-ohne-text'));
        self::assertStringNotContainsString('/eventlokal/haus-ohne-text', $this->sitemap());
        self::assertStringNotContainsString('/eventlokal/haus-ohne-text', $this->get('/llms.txt'));
    }

    public function test_an_entry_outside_the_launch_cantons_is_indexed_by_the_gate_alone(): void
    {
        // D21's launch cantons limit landing pages only; an entry's own page follows VenueIndexGate in any canton.
        $launch = self::getContainer()->getParameter('landing.launch_cantons');
        self::assertIsArray($launch);
        self::assertNotContains('lu', $launch);
        $town = repository(Town::class)->findOneBy(['name' => 'Luzern']);
        self::assertInstanceOf(Town::class, $town);
        $venue = VenueFactory::createOne(['name' => 'Haus am See', 'status' => VenueStatus::Published, 'town' => $town]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'haus-luzern']);
        self::assertSame('lu', $this->connection->fetchOne('SELECT t.canton_code FROM venues v JOIN towns t ON t.id = v.town_id WHERE v.id = ?', [$venue->id()]));

        self::assertStringNotContainsString('noindex', $this->publicRobots('haus-luzern'));
        self::assertStringContainsString('/eventlokal/haus-luzern', $this->sitemap());
        self::assertStringContainsString('/eventlokal/haus-luzern', $this->get('/llms.txt'));
    }

    public function test_a_second_click_writes_nothing_and_a_missing_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue('haus-doppelt', unchecked: true);
        $form = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter('form[name="check_facts"]')->form();
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSame(1, $this->auditRows($venue->number()));

        $this->client->request('POST', '/admin/locations/'.$venue->number().'/fakten-offen');
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertNotNull($this->connection->fetchOne('SELECT facts_checked_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame(1, $this->auditRows($venue->number()));
    }

    private function venue(string $slug, bool $unchecked = false, ?string $description = VenueTranslationFactory::DESCRIPTION): Venue
    {
        $factory = VenueFactory::new();
        if ($unchecked) {
            $factory = $factory->withUncheckedFacts();
        }
        $venue = $factory->create(['name' => 'haus-ungeprueft' === $slug ? 'Haus Ungeprüft' : 'Haus '.$slug, 'status' => VenueStatus::Published]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'description' => $description]);

        return $venue;
    }

    private function publicRobots(string $slug): string
    {
        $crawler = $this->client->request('GET', '/eventlokal/'.$slug);
        self::assertResponseIsSuccessful();
        $robots = $crawler->filter('meta[name="robots"]');

        return 0 === $robots->count() ? '' : (string) $robots->attr('content');
    }

    /**
     * The first venue sitemap, '' when it does not exist (no indexable entry: a 404).
     */
    private function sitemap(): string
    {
        $body = $this->get('/sitemap-venues-1.xml');

        return 404 === $this->client->getResponse()->getStatusCode() ? '' : $body;
    }

    private function get(string $path): string
    {
        $this->client->request('GET', $path);

        return (string) $this->client->getResponse()->getContent();
    }

    private function auditRows(int $number): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ? AND command LIKE '%CheckVenueFacts'", [(string) $number]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
