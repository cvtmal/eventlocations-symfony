<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Inquiries\Command\DeliverInquiry;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G8: the guest's inquiry, delivered through the real pipeline (test mailer `null://null`), is the funnel step
 * `inquiry_delivered` of that venue, and the row carries no address. The failed delivery records nothing:
 * `SubmitInquiryDeliveryFailureTest`.
 */
final class SubmitInquiryFunnelTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    protected function setUp(): void
    {
        $this->start();
        $this->connection->executeStatement('DELETE FROM funnel_events');
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    public function test_a_delivered_inquiry_is_one_funnel_step_of_its_venue_without_personal_data(): void
    {
        $venue = DetailVenues::full($this->em);

        $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['email' => 'gast.beispiel@example.ch', 'firstName' => 'Gast', 'lastName' => 'Beispiel']));

        self::assertSame('sent', $this->rows()[0]['venue_mail_status']);
        $events = $this->connection->fetchAllAssociative('SELECT event, venue_id, campaign_code FROM funnel_events');
        self::assertSame([['event' => 'inquiry_delivered', 'venue_id' => $venue->id(), 'campaign_code' => null]], $events);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events e WHERE e::text LIKE '%@%' OR e::text ILIKE '%beispiel%'"));
    }

    public function test_the_same_inquiry_is_never_counted_twice(): void
    {
        DetailVenues::full($this->em);
        $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid());
        $id = (string) $this->rows()[0]['id'];

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new DeliverInquiry($id));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'), 'a sent inquiry is not mailed or counted again');
    }
}
