<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

final class VenueStatusActionsTest extends WebTestCase
{
    use AdminSession {
        tearDown as private endAdminSession;
    }
    use Factories;
    use ImportsTownDataset;

    private ?ContentFolder $content = null;

    protected function tearDown(): void
    {
        $this->content?->removeAll();
        $this->endAdminSession();
    }

    public function test_a_new_venue_is_published_once_it_has_what_it_needs(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin/locations/neu');
        $this->client->submitForm('Erfassen', ['create_venue[name]' => 'Neue Scheune', 'create_venue[capacityMax]' => '140']);
        $crawler = $this->client->followRedirect();
        $number = (int) $this->connection->fetchOne("SELECT number FROM venues WHERE name = 'Neue Scheune'");

        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertResponseRedirects('/admin/locations/'.$number);
        $crawler = $this->client->followRedirect();
        $toast = $crawler->filter('.toast')->text();
        self::assertSame('Noch nicht publizierbar. Es fehlt: Kurztext, Wichtigste Art der Location, Mindestens ein Foto. Fakten nicht geprüft, Beschreibung fehlt.', $toast);
        self::assertSame(1, substr_count($toast, 'Es fehlt'), 'the frame says it once, each checklist gap is a noun (#45 F-m2)');
        self::assertSame('unpublished', $this->venueStatus($number));
        self::assertSame(1, $this->auditRows($number), 'only the creation');

        $venue = $this->entity($number);
        $this->makePublishable($venue);
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertResponseRedirects('/admin/locations/'.$number);
        $crawler = $this->client->followRedirect();
        self::assertSame('«Neue Scheune» ist publiziert.', $crawler->filter('.toast')->text());
        self::assertSame('published', $this->venueStatus($number));
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
        self::assertSame(2, $this->auditRows($number));
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE number = ?', [$number]));
        self::assertCount(1, $crawler->filter('form[name="unpublish"]'));
        // A published entry writes no CRM task: a new entry is Free and nobody invoices it (F12, D2).
        self::assertSame(0, $this->crmTasks($number));
    }

    /**
     * The sister site's rule "remind the owner to invoice a new entry" (Q20, POR-14) is gone with F12: a new entry is
     * Free and not invoiced, so the first publish writes no task and no company, and neither does offline and back.
     */
    public function test_the_first_publish_of_a_new_venue_writes_no_task(): void
    {
        $this->logInAs();
        $number = $this->createPublishable('Neue Scheune');
        $crawler = $this->client->request('GET', '/admin/locations/'.$number);

        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('published', $this->venueStatus($number));
        self::assertSame(0, $this->crmTasks($number));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_companies'));

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[name="unpublish"]')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('published', $this->venueStatus($number));
        self::assertSame(0, $this->crmTasks($number), 'offline and back writes none');
    }

    public function test_publishing_an_imported_or_seeded_venue_writes_no_task(): void
    {
        $this->logInAs();
        $venue = DetailVenues::minimal();
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);
        $crawler = $this->client->request('GET', $this->url($venue));

        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('published', $this->venueStatus($venue->number()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_companies'));
    }

    /**
     * D54 pin: the CMS refuses to publish an unmanaged entry without «Fakten geprüft» and a description. Each half on
     * its own keeps it offline; with both it goes online.
     */
    public function test_the_cms_refuses_to_publish_an_unmanaged_entry_without_checked_facts_and_a_description(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished', facts_checked_at = NULL WHERE id = ?", [$venue->id()]);
        $this->connection->executeStatement("UPDATE venue_translations SET description = 'Ein Gartenhaus für 80 Gäste.' WHERE venue_id = ?", [$venue->id()]);

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('Noch nicht publizierbar. Fakten nicht geprüft.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('unpublished', $this->venueStatus($venue->number()));

        $this->connection->executeStatement('UPDATE venues SET facts_checked_at = now() WHERE id = ?', [$venue->id()]);
        $this->connection->executeStatement('UPDATE venue_translations SET description = NULL WHERE venue_id = ?', [$venue->id()]);
        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('Noch nicht publizierbar. Beschreibung fehlt.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('unpublished', $this->venueStatus($venue->number()));

        $this->connection->executeStatement("UPDATE venue_translations SET description = 'Ein Gartenhaus für 80 Gäste.' WHERE venue_id = ?", [$venue->id()]);
        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('published', $this->venueStatus($venue->number()));
    }

    public function test_unpublish_takes_a_venue_offline(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $crawler = $this->client->request('GET', $this->url($venue));

        $this->client->submit($crawler->filter('form[name="unpublish"]')->form());

        self::assertResponseRedirects($this->url($venue));
        self::assertSame('«Gartenhaus Minimal» ist offline.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('unpublished', $this->venueStatus($venue->number()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->auditRows($venue->number()));
    }

    public function test_a_venue_already_in_that_state_dispatches_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $crawler = $this->client->request('GET', $this->url($venue));
        // Another tab took it offline meanwhile.
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);

        $this->client->submit($crawler->filter('form[name="unpublish"]')->form());

        self::assertSame('«Gartenhaus Minimal» war schon offline.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, $this->auditRows($venue->number()));
        self::assertSame([], $this->purger()->venues);
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_a_missing_csrf_token_changes_nothing(): void
    {
        $this->logInAs();
        $venue = DetailVenues::minimal();

        foreach (['unpublizieren', 'loeschen'] as $action) {
            $this->client->request('POST', $this->url($venue).'/'.$action);

            self::assertResponseRedirects($this->url($venue), null, $action);
            self::assertStringContainsString('Das hat nicht geklappt', $this->client->followRedirect()->filter('.toast')->text(), $action);
        }
        self::assertSame('published', $this->venueStatus($venue->number()));
        self::assertSame(0, $this->auditRows($venue->number()));
    }

    public function test_an_admin_deletes_an_unpublished_venue(): void
    {
        $this->logInAs();
        $venue = DetailVenues::minimal();
        $number = $venue->number();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);
        $crawler = $this->client->request('GET', $this->url($venue));

        $this->client->submit($crawler->filter('#venue-delete form[name="delete"]')->form());

        self::assertResponseRedirects('/admin/locations');
        self::assertSame('«Gartenhaus Minimal» ist gelöscht.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM venues WHERE number = ?', [$number]));
        self::assertSame(1, $this->auditRows($number));
        $this->client->request('GET', '/admin/locations/'.$number);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_published_venue_is_not_deleted(): void
    {
        $this->logInAs();
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);
        $crawler = $this->client->request('GET', $this->url($venue));
        // Published again in another tab before the confirmation.
        $this->connection->executeStatement("UPDATE venues SET status = 'published' WHERE id = ?", [$venue->id()]);

        $this->client->submit($crawler->filter('#venue-delete form[name="delete"]')->form());

        self::assertResponseRedirects($this->url($venue));
        self::assertSame('Ein veröffentlichter Eintrag kann nicht gelöscht werden. Bitte zuerst offline nehmen.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame('published', $this->venueStatus($venue->number()));
        self::assertSame(0, $this->auditRows($venue->number()));
    }

    public function test_an_editor_cannot_delete(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);

        $this->client->request('GET', $this->url($venue));
        self::assertSelectorNotExists('#venue-delete');
        self::assertSelectorNotExists('form[name="delete"]');

        $this->client->request('POST', $this->url($venue).'/loeschen');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_anonymous_and_unknown_numbers(): void
    {
        $this->client->request('POST', '/admin/locations/1/publizieren');
        self::assertResponseRedirects('/admin/login');

        $this->logInAs();
        $this->client->request('POST', '/admin/locations/99999999/publizieren');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_import_does_not_bring_an_unpublish_back(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $this->content = new ContentFolder($kernel->getProjectDir().'/var/test-content-'.uniqid());
        $this->content->venue('gasthaus-status', ['translations' => ['de' => ['description' => 'Fiktive Beschreibung der Location.']]]);
        $import = new CommandTester((new Application($kernel))->find('app:venues:import'));
        $import->execute(['path' => $this->content->path()]);
        // F14 (D54): an imported entry is a draft until staff check its facts; then the import puts it online.
        $this->connection->executeStatement("UPDATE venues SET facts_checked_at = now() WHERE content_key = 'gasthaus-status'");
        $import->execute(['path' => $this->content->path(), '--force' => true]);
        $number = (int) $this->connection->fetchOne("SELECT number FROM venues WHERE content_key = 'gasthaus-status'");
        self::assertSame('published', $this->venueStatus($number), $import->getDisplay());

        $this->logInAs(AdminRole::Editor);
        $crawler = $this->client->request('GET', '/admin/locations/'.$number);
        $this->client->submit($crawler->filter('form[name="unpublish"]')->form());
        self::assertSame('unpublished', $this->venueStatus($number));

        $this->content->change('gasthaus-status', ['capacity' => ['max' => 200]]);
        $import->execute(['path' => $this->content->path(), '--force' => true]);

        self::assertStringContainsString('cms_edited  gasthaus-status', $import->getDisplay());
        self::assertSame('unpublished', $this->venueStatus($number));
        self::assertSame(120, (int) $this->connection->fetchOne('SELECT capacity_max FROM venues WHERE number = ?', [$number]));
    }

    /**
     * A venue made with "Neue Location", given what a publish needs.
     */
    private function createPublishable(string $name): int
    {
        $this->client->request('GET', '/admin/locations/neu');
        $this->client->submitForm('Erfassen', ['create_venue[name]' => $name, 'create_venue[capacityMax]' => '140']);
        $number = (int) $this->connection->fetchOne('SELECT number FROM venues WHERE name = ?', [$name]);
        $this->makePublishable($this->entity($number));

        return $number;
    }

    /**
     * What a publish needs, the draft rule included (F14, D54): summary, main building, photo, checked facts and a
     * description. Nobody manages a venue made in the CMS.
     */
    private function makePublishable(Venue $venue): void
    {
        $this->connection->executeStatement("UPDATE venue_translations SET summary = 'Eine Scheune am Waldrand.', description = 'Eine alte Scheune mit Platz für 140 Gäste.' WHERE venue_id = ? AND locale = 'de'", [$venue->id()]);
        $this->connection->executeStatement('UPDATE venues SET facts_checked_at = now() WHERE id = ?', [$venue->id()]);
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
    }

    private function crmTasks(int $number): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM crm_tasks t JOIN crm_companies l ON l.id = t.company_id JOIN venues v ON v.id = l.venue_id WHERE v.number = ?',
            [$number],
        );
    }

    private function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number();
    }

    private function venueStatus(int $number): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM venues WHERE number = ?', [$number]);
    }

    private function auditRows(int $number): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $number]);
    }

    private function entity(int $number): Venue
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $venue = $em->getRepository(Venue::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Venue::class, $venue);

        return $venue;
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
