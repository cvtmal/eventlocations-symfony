<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Extension\AccessRequestsWidget;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Prüfung" in the sidebar and "Zugriffsanfragen" on the dashboard.
 */
final class AccessRequestsNavigationTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string QUEUE = '/admin/pruefung';
    private const string ITEM = '.sidebar__item[href="'.self::QUEUE.'"]';

    public function test_the_badge_counts_pending_rows_only(): void
    {
        $this->logInAs(AdminRole::Editor);
        PortalVenueAccessFactory::createOne();
        PortalVenueAccessFactory::createOne();
        PortalVenueAccessFactory::new()->active()->create();

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame(self::ITEM.' .sidebar__badge', '2');
    }

    public function test_no_badge_at_zero(): void
    {
        $this->logInAs(AdminRole::Editor);
        PortalVenueAccessFactory::new()->active()->create();

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::ITEM);
        self::assertSelectorNotExists(self::ITEM.' .sidebar__badge');
    }

    public function test_the_item_stays_active_on_the_queue(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::QUEUE);

        $active = $crawler->filter('.sidebar__item[aria-current="page"]');
        self::assertSame('Prüfung', $active->filter('.sidebar__label')->text());
        self::assertSame(self::QUEUE, $active->attr('href'));
        self::assertCount(1, $active->filter('use[href$="#shield"]'));
    }

    public function test_the_widget_lists_the_oldest_requests_up_to_its_limit(): void
    {
        $this->logInAs(AdminRole::Editor);
        $first = VenueFactory::createOne(['name' => 'Erste Location']);
        $second = VenueFactory::createOne(['name' => 'Zweite Location']);
        self::assertLessThan($second->number(), $first->number());
        // Written newest first and the tie the wrong way round, so only the order of the query can sort them.
        $this->request('Sechs', '2026-10-06 10:00:00');
        $this->request('Fünf', '2026-10-05 10:00:00');
        $this->request('Vier', '2026-10-04 10:00:00');
        $this->request('Drei', '2026-10-03 10:00:00');
        $this->request('Zwei', '2026-10-02 10:00:00', $second);
        $this->request('Eins', '2026-10-02 10:00:00', $first);

        $widget = $this->widget();

        self::assertSame(AccessRequestsWidget::LIMIT, $widget->filter('tbody tr')->count());
        self::assertSame(
            ['Eins Paar', 'Zwei Paar', 'Drei Paar', 'Vier Paar', 'Fünf Paar'],
            $widget->filter('tbody strong')->each(static fn (Crawler $name): string => $name->text()),
        );
        self::assertSame(['Name', 'Eintrag', 'Angefragt am'], $widget->filter('thead th')->each(static fn (Crawler $head): string => $head->text()));
        $cells = $widget->filter('tbody tr')->first()->filter('td');
        self::assertSame('Erste Location', $cells->eq(1)->text());
        self::assertSame('02.10.2026', $cells->eq(2)->text());
        self::assertSame('Alle 6 ansehen', $widget->filter('.widget__head-link a')->text());
        self::assertCount(0, $widget->filter('button, form'), 'The decision stays in the queue.');
    }

    public function test_the_widget_links_to_the_queue(): void
    {
        $this->logInAs(AdminRole::Editor);
        PortalVenueAccessFactory::createOne();

        $link = $this->widget()->filter('.widget__head-link a');

        self::assertSame('Alle ansehen', $link->text());
        self::assertSame(self::QUEUE, $link->attr('href'));
    }

    public function test_the_widget_shows_its_empty_text(): void
    {
        $this->logInAs(AdminRole::Editor);

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $widget = $this->widget();

        self::assertSame('Keine offenen Zugriffsanfragen.', $widget->filter('.card__body')->text());
        self::assertCount(0, $widget->filter('table'));
        $statements = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'portal_venue_access'),
        );
        self::assertCount(1, $statements, 'The badge alone: an empty queue costs the widget no statement.');
    }

    private function request(string $firstName, string $at, ?Venue $venue = null): void
    {
        PortalVenueAccessFactory::createOne([
            'user' => PortalUserFactory::new(['firstName' => $firstName, 'lastName' => 'Paar']),
            'venue' => $venue ?? VenueFactory::new(),
            'requestedAt' => new \DateTimeImmutable($at),
        ]);
    }

    private function widget(): Crawler
    {
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        return $crawler->filter('.widget')->reduce(static fn (Crawler $widget): bool => 'Zugriffsanfragen' === $widget->filter('h2')->text());
    }
}
