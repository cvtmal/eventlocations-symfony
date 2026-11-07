<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\ModerationCaseFactory;
use App\Factory\ModerationCasePhotoFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\ModerationCase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * G2 in the back office: the "Meldungen" queue and one complaint's page, behind the editor login, and the two
 * actions (take the entry offline, decide) as an editor uses them. The tests read the HTML and the rows.
 */
final class ModerationQueueTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $case = ModerationCaseFactory::createOne();

        foreach (['/admin/meldungen', '/admin/meldungen/'.$case->id()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/admin/login', 302, $url);
        }
        $this->client->request('POST', '/admin/meldungen/'.$case->id().'/entscheiden', ['moderation_decision' => ['keep' => '']]);
        self::assertResponseRedirects('/admin/login', 302);
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM moderation_cases'));
    }

    public function test_the_queue_lists_open_cases_then_decided_ones(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $rights = $this->rightsCase($venue);
        ModerationCasePhotoFactory::createOne(['case' => $rights, 'photo' => $photo]);
        ModerationCaseFactory::createOne(['venueReference' => 'Gasthaus zur Linde']);
        ModerationCaseFactory::new()->decided()->create(['complainantName' => 'Entschiedene Person']);

        $crawler = $this->client->request('GET', '/admin/meldungen');

        self::assertResponseIsSuccessful();
        $open = $crawler->filter('#moderation-open tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertCount(2, $open);
        self::assertSame(['Bildrechte', 'Gartenhaus Minimal', 'Erika Beispiel', '1', 'Offen'], \array_slice($open[0], 1));
        self::assertSame(['Angaben korrigieren', 'Gasthaus zur Linde', 'Erika Beispiel', '0', 'Offen'], \array_slice($open[1], 1));
        self::assertSame('/admin/meldungen/'.$rights->id(), $crawler->filter('#moderation-open tbody tr')->first()->filter('a')->first()->attr('href'));
        self::assertSame('/admin/locations/'.$venue->number(), $crawler->filter('#moderation-open tbody tr')->first()->filter('td')->eq(2)->filter('a')->attr('href'));
        self::assertStringContainsString('Entschiedene Person', $crawler->filter('#moderation-decided')->text());
        self::assertStringContainsString('Entschieden: behalten', $crawler->filter('#moderation-decided')->text());
        self::assertSame('Meldungen 2', $crawler->filter('h1')->text());
        self::assertStringContainsString('Meldungen', $crawler->filter('a[href="/admin/meldungen"]')->first()->text());
    }

    public function test_the_case_page_shows_the_complaint_the_held_photo_and_the_actions(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $case = $this->rightsCase($venue);
        ModerationCasePhotoFactory::createOne(['case' => $case, 'photo' => $photo]);

        $crawler = $this->client->request('GET', '/admin/meldungen/'.$case->id());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Bildrechte vom', $crawler->filter('h1')->text());
        self::assertStringContainsString('Das erste Foto habe ich aufgenommen.', $crawler->filter('.moderation__message')->text());
        self::assertStringContainsString('erika.beispiel@example.ch', $crawler->filter('section[aria-labelledby="meldung"]')->text());
        self::assertCount(1, $crawler->filter('.moderation__photo img'));
        self::assertStringContainsString($photo->id(), (string) $crawler->filter('.moderation__photo img')->attr('src'));
        self::assertStringContainsString('Verborgen', $crawler->filter('.moderation__photo')->text());
        self::assertSame('Eintrag offline nehmen', $crawler->filter('.moderation__offline button')->text());
        self::assertSame(['Entscheiden: entfernen', 'Entscheiden: behalten'], $crawler->filter('.moderation__actions button')->each(static fn (Crawler $button): string => $button->text()));

        $this->client->request('GET', '/admin/meldungen/019a0000-0000-7000-8000-000000000999');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_fotos_tab_marks_a_held_photo(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        ModerationCasePhotoFactory::createOne(['case' => $this->rightsCase($venue), 'photo' => $photo]);

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number().'/fotos');

        self::assertStringContainsString('gemeldet (verborgen)', $crawler->filter('#photo-rights tbody tr')->first()->text());
    }

    public function test_taking_the_entry_offline_needs_the_token_and_then_unpublishes(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $case = ModerationCaseFactory::createOne(['kind' => ModerationKind::Removal, 'venue' => $venue, 'venueReference' => null]);
        $crawler = $this->client->request('GET', '/admin/meldungen/'.$case->id());
        $form = $crawler->filter('.moderation__offline')->form();

        $this->client->request('POST', '/admin/meldungen/'.$case->id().'/offline', ['_token' => 'falsch']);
        self::assertResponseRedirects('/admin/meldungen/'.$case->id(), 303);
        self::assertSame('Das hat nicht geklappt. Lade die Seite neu und versuche es noch einmal.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('published', $this->venueStatus($venue));

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/meldungen/'.$case->id(), 303);
        self::assertSame('Der Eintrag ist offline.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('unpublished', $this->venueStatus($venue));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT took_entry_offline FROM moderation_cases'));
        self::assertCount(0, $this->client->getCrawler()->filter('.moderation__offline'), 'the entry is offline: no second button');
    }

    public function test_deciding_keep_lifts_the_hold_and_shows_the_decision(): void
    {
        $staff = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $venue = DetailVenues::minimal();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $case = $this->rightsCase($venue);
        ModerationCasePhotoFactory::createOne(['case' => $case, 'photo' => $photo]);
        $crawler = $this->client->request('GET', '/admin/meldungen/'.$case->id());
        $form = $crawler->selectButton('Entscheiden: behalten')->form(['moderation_decision[note]' => 'Der Betreiber hat die Rechte belegt.']);

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/meldungen/'.$case->id(), 303);
        $page = $this->client->followRedirect();
        self::assertSame('Die Meldung ist entschieden. Die Inhalte sind wieder sichtbar.', $page->filter('.toast')->text());
        self::assertStringContainsString('Andrea Keller', $page->filter('section[aria-labelledby="entscheidung"]')->text());
        self::assertStringContainsString('Der Betreiber hat die Rechte belegt.', $page->filter('section[aria-labelledby="entscheidung"]')->text());
        self::assertCount(0, $page->filter('.moderation__actions'));
        self::assertSame(['decided', 'keep', $staff['user']->id()], array_values((array) $this->connection->fetchAssociative('SELECT status, decision, decided_by FROM moderation_cases')));

        $this->client->request('GET', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);
        self::assertStringContainsString($photo->id(), (string) $this->client->getResponse()->getContent());

        // The same form again (a second tab): the case is decided, nothing changes.
        $this->client->submit($form);
        self::assertSame('Diese Meldung ist schon entschieden.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('keep', $this->connection->fetchOne('SELECT decision FROM moderation_cases'));
    }

    public function test_deciding_remove_deletes_the_named_photo(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $case = $this->rightsCase($venue);
        ModerationCasePhotoFactory::createOne(['case' => $case, 'photo' => $photo]);
        $crawler = $this->client->request('GET', '/admin/meldungen/'.$case->id());

        $this->client->submit($crawler->selectButton('Entscheiden: entfernen')->form());

        self::assertSame('Die Meldung ist entschieden. Die gemeldeten Fotos sind entfernt.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE id = ?', [$photo->id()]));
        self::assertStringContainsString('Entfernt', $this->client->getCrawler()->filter('.moderation__photo')->text());
        self::assertSame(ModerationDecision::Remove->value, $this->connection->fetchOne('SELECT decision FROM moderation_cases'));
    }

    private function rightsCase(Venue $venue): ModerationCase
    {
        return ModerationCaseFactory::createOne([
            'kind' => ModerationKind::Rights,
            'venue' => $venue,
            'venueReference' => null,
            'message' => 'Das erste Foto habe ich aufgenommen.',
        ]);
    }

    private function venueStatus(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM venues WHERE id = ?', [$venue->id()]);
    }
}
