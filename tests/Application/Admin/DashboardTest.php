<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\Extension\DashboardWidgetRegistry;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Tests\Application\Crm\CountsQueries;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Zenstruck\Foundry\Test\Factories;

final class DashboardTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use CountsQueries;
    use Factories;

    public function test_it_greets_by_first_name_with_the_long_german_date(): void
    {
        self::mockTime('2026-10-29 10:00:00 UTC');
        $this->logInAs(displayName: 'Andrea Keller');

        $this->client->request('GET', '/admin');

        self::assertSelectorTextSame('h1', 'Guten Tag, Andrea');
        self::assertSelectorTextSame('.dashboard__date', 'Donnerstag, 29. Oktober 2026');
    }

    public function test_the_date_is_the_zurich_one(): void
    {
        self::mockTime('2026-10-30 23:30:00 UTC');
        $this->logInAs();

        $this->client->request('GET', '/admin');

        self::assertSelectorTextSame('.dashboard__date', 'Samstag, 31. Oktober 2026');
    }

    public function test_it_shows_the_cms_widgets_and_the_new_action(): void
    {
        $this->logInAs(displayName: 'Andrea Keller');
        $hut = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Waldhütte Eichholz', 'status' => VenueStatus::Unpublished]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("UPDATE venues SET cms_edited_at = '2026-10-28 09:00:00+00' WHERE id = ?", [$hut->id()]);

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $new = $crawler->filter('.topbar__actions a.btn--primary');
        self::assertSame('Neu', $new->text());
        self::assertSame('/admin/locations', $new->attr('href'));
        self::assertSame(['Neue Anfragen', 'Meine Aufgaben', 'Überfällig', 'Verlängerungen', 'Unvollständige Locations', 'Zuletzt bearbeitet'], $crawler->filter('.widget h2')->each(static fn (Crawler $heading): string => $heading->text()));

        $incomplete = $crawler->filter('.widget')->eq(4);
        self::assertSame('/admin/locations/'.$hut->number(), $incomplete->filter('.venue-widget__name')->attr('href'));
        self::assertSame(['Keine Fotos', 'Keine Anfrage-E-Mail', 'Kurztext fehlt', 'Haupt-Gebäudeart fehlt'], $incomplete->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text()));
        $all = $incomplete->filter('.venue-widget__foot a');
        self::assertSame('1 unvollständige ansehen', $all->text());
        self::assertSame('/admin/locations?status=incomplete', $all->attr('href'));

        $recent = $crawler->filter('.widget')->eq(5);
        self::assertSame('Waldhütte Eichholz', $recent->filter('.venue-widget__name')->text());
        self::assertSame('Nr. '.$hut->number(), $recent->filter('.venue-widget__main .muted')->text());
        self::assertSame('28.10.2026', $recent->filter('.venue-widget__meta')->text());
    }

    public function test_the_page_stays_within_its_statements(): void
    {
        ['user' => $me] = $this->logInAs();
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('yesterday')]);
        VenueFactory::new()->withoutLocation(null)->create(['status' => VenueStatus::Unpublished]);

        // The two badges, the inquiry one and the task one, which have no owners statement to ride on here, "Neue Anfragen",
        // "Meine Aufgaben", "Überfällig", "Verlängerungen", the incomplete venues and their total, the recently edited
        // venues; with the user's refresh 10 in production (#29's ceiling 10).
        self::assertSame(9, $this->queriesOf('/admin'));

        // An empty identity map is production's: the refresh then reads the user.
        $this->entityManager()->clear();
        self::assertSame(10, $this->queriesOf('/admin'));
    }

    public function test_each_widget_has_its_own_empty_text(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(['Noch keine Anfragen.', 'Keine offenen Aufgaben.', 'Nichts ist überfällig.', 'In den nächsten 60 Tagen endet kein Vertrag.', 'Alle Locations sind vollständig.', 'Noch nichts im Backoffice bearbeitet.'], $crawler->filter('.widget .card__body')->each(static fn (Crawler $text): string => $text->text()));
    }

    public function test_without_widgets_it_shows_the_empty_state(): void
    {
        $this->logInAs();
        // The CMS registers its widgets; an empty registry stands for a back office with no content module.
        $authorization = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $authorization);
        self::getContainer()->set(DashboardWidgetRegistry::class, new DashboardWidgetRegistry([], $authorization));

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('.empty__title', 'Noch keine Inhalte');
        self::assertSelectorNotExists('.widget');
    }
}
