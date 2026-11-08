<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\EventListener\CreateProTaskListener;
use App\Crm\Exception\VenueNotFound;
use App\Crm\ProRequestTitle;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Portal\Event\PortalEvents;
use App\Portal\Event\ProRequested;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * F12: "Pro anfragen" reaches the CRM through the published `ProRequested`, with the listener the container wires. The
 * task goes to the venue's company, a venue without one gets one first, a second press leaves no second task and no
 * audit row, and a failing CRM stays out of the manager's request.
 */
final class ProRequestedTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_the_event_writes_the_task_on_the_venues_company(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenue($venue)->create(['owner' => AdminUserFactory::createOne()]);

        $this->publishes($this->event($venue));

        self::assertSame(
            ['Pro-Anfrage: Schloss Seeblick (anna@example.ch)', $company->id()],
            array_values((array) $this->connection->fetchAssociative('SELECT title, company_id FROM crm_tasks')),
        );
        self::assertSame(1, $this->rows("SELECT count(*) FROM admin_audit_log WHERE command LIKE '%CreateProRequestTask'"));
    }

    public function test_a_venue_without_a_company_gets_one_for_the_task(): void
    {
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);

        $this->publishes($this->event($venue));

        $companyId = $this->connection->fetchOne('SELECT id FROM crm_companies WHERE venue_id = ?', [$venue->id()]);
        self::assertIsString($companyId);
        self::assertSame($companyId, $this->connection->fetchOne("SELECT company_id FROM crm_tasks WHERE title LIKE 'Pro-Anfrage:%'"));
    }

    public function test_a_second_press_writes_no_second_task_and_no_audit_row(): void
    {
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $this->publishes($this->event($venue));
        $audited = $this->rows('SELECT count(*) FROM admin_audit_log');

        $this->publishes($this->event($venue));

        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_tasks WHERE title LIKE 'Pro-Anfrage:%'"));
        self::assertSame($audited, $this->rows('SELECT count(*) FROM admin_audit_log'));
    }

    public function test_a_crm_failure_is_logged_without_the_venue_or_the_address_and_never_escapes(): void
    {
        $venueId = EntityId::generate();
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $companies = self::getContainer()->get(VenueCompanyQuery::class);
        self::assertInstanceOf(VenueCompanyQuery::class, $companies);
        $titles = self::getContainer()->get(ProRequestTitle::class);
        self::assertInstanceOf(ProRequestTitle::class, $titles);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ProRequested::class, new CreateProTaskListener($bus, $companies, $titles, new Logger(LogLevel::ERROR, $output)));

        (new PortalEvents($dispatcher, new NullLogger()))->proRequested(new ProRequested($venueId, 'Schloss Seeblick', 'anna@example.ch', 'Anna', 'Meier'));

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue '.$venueId.': writing the Pro request task failed ('.VenueNotFound::class.' at ', $line);
        self::assertStringNotContainsString(VenueNotFound::forId($venueId)->getMessage(), $line);
        self::assertStringNotContainsString('anna', $line);
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks'));
    }

    private function publishes(ProRequested $event): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        (new PortalEvents($dispatcher, new NullLogger()))->proRequested($event);
    }

    private function event(Venue $venue): ProRequested
    {
        return new ProRequested($venue->id(), $venue->name(), 'anna@example.ch', 'Anna', 'Meier');
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }
}
