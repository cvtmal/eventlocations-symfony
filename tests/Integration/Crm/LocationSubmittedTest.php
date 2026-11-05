<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\CommandHandler\LinkLocationManagerHandler;
use App\Crm\EventListener\LinkLocationManagerListener;
use App\Crm\Exception\VenueNotFound;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Portal\Event\LocationSubmitted;
use App\Portal\Event\PortalEvents;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * POR-12: a submitted location reaches the CRM through #43's listener, with its own wording on the timeline. A location
 * from the wizard has no address yet, and a failing CRM stays out of the manager's submit.
 */
final class LocationSubmittedTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_the_event_creates_the_company_and_the_contact(): void
    {
        $venue = $this->draft();

        $this->publishes($this->event($venue));

        $companyId = $this->connection->fetchOne('SELECT id FROM crm_companies WHERE venue_id = ?', [$venue->id()]);
        self::assertIsString($companyId);
        self::assertSame('Schloss Seeblick', $this->connection->fetchOne('SELECT name FROM crm_companies WHERE id = ?', [$companyId]));
        self::assertSame([
            'first_name' => 'Petra',
            'last_name' => 'Keller',
            'email' => 'petra@example.ch',
            'phone' => '044 123 45 67',
            'position' => LinkLocationManagerHandler::MANAGER_POSITION,
            'company_id' => $companyId,
        ], $this->connection->fetchAssociative('SELECT first_name, last_name, email, phone, position, company_id FROM crm_contacts'));
    }

    public function test_the_timeline_entry_uses_the_submission_wording(): void
    {
        $venue = $this->draft();

        $this->publishes($this->event($venue));

        self::assertSame('Neuer Eintrag eingereicht: Schloss Seeblick', $this->connection->fetchOne('SELECT body FROM crm_activities'));
    }

    public function test_a_contact_on_another_location_gets_a_row_on_the_new_one(): void
    {
        $venue = $this->draft();
        ContactFactory::createOne(['email' => 'petra@example.ch']);

        $this->publishes($this->event($venue));

        $companyId = $this->connection->fetchOne('SELECT id FROM crm_companies WHERE venue_id = ?', [$venue->id()]);
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM crm_contacts WHERE company_id = ? AND email = 'petra@example.ch'", [$companyId]));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM crm_activity_companies WHERE company_id = ?', [$companyId]));
    }

    public function test_a_crm_failure_is_logged_without_its_message_and_never_escapes(): void
    {
        $venueId = EntityId::generate();
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $companies = self::getContainer()->get(VenueCompanyQuery::class);
        self::assertInstanceOf(VenueCompanyQuery::class, $companies);
        $listener = new LinkLocationManagerListener($bus, $companies, new Logger(LogLevel::ERROR, $output));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LocationSubmitted::class, $listener->onLocationSubmitted(...));

        (new PortalEvents($dispatcher, new NullLogger()))->locationSubmitted(new LocationSubmitted($venueId, 'Schloss Seeblick', 'petra@example.ch', 'Petra', 'Keller', null));

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue '.$venueId.': linking the location manager to the CRM failed ('.VenueNotFound::class.' at ', $line);
        self::assertStringNotContainsString(VenueNotFound::forId($venueId)->getMessage(), $line);
        self::assertStringNotContainsString('petra', $line);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
    }

    private function publishes(LocationSubmitted $event): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        (new PortalEvents($dispatcher, new NullLogger()))->locationSubmitted($event);
    }

    private function event(Venue $venue): LocationSubmitted
    {
        return new LocationSubmitted($venue->id(), $venue->name(), 'petra@example.ch', 'Petra', 'Keller', '044 123 45 67');
    }

    private function draft(): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'status' => VenueStatus::Unpublished]);
    }
}
