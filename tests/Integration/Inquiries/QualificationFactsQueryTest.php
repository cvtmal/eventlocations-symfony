<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Inquiries\ReadModel\QualificationFactsQuery;
use App\Inquiries\ValueObject\QualificationFacts;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class QualificationFactsQueryTest extends KernelTestCase
{
    use Factories;

    private const string CREATED = '2026-11-08 10:00:00 UTC';
    private const string ID = '019a0000-0000-7000-8000-00000000f001';

    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_venue_event_types_are_listed_and_the_facts_come_back_undelivered(): void
    {
        $venue = $this->venue();
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'seminar')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'apero')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->create(['venue' => $venue]);

        $facts = $this->facts($venue);

        self::assertFalse($facts->delivered);
        self::assertSame(['apero', 'seminar'], $facts->venueEventTypes);
        self::assertSame('seminar', $facts->eventType);
        self::assertEquals(new \DateTimeImmutable(self::CREATED), $facts->createdAt);
    }

    public function test_a_venue_that_lists_no_event_types_gives_an_empty_list(): void
    {
        self::assertSame([], $this->facts($this->venue())->venueEventTypes);
    }

    public function test_the_guests_fit_the_venue_range(): void
    {
        $venue = $this->venue(min: 20, max: 100);

        self::assertTrue($this->facts($venue, guests: 100)->guestsFit);
        self::assertTrue($this->facts($venue, guests: 20)->guestsFit);
        self::assertFalse($this->facts($venue, guests: 101)->guestsFit);
        self::assertFalse($this->facts($venue, guests: 19)->guestsFit);
    }

    public function test_the_guests_fit_a_room_range_only(): void
    {
        $venue = $this->venue(min: 50, max: 100);
        RoomFactory::createOne(['venue' => $venue, 'capacityMin' => 10, 'capacityMax' => 30]);

        self::assertTrue($this->facts($venue, guests: 15)->guestsFit);
        self::assertFalse($this->facts($venue, guests: 40)->guestsFit);
        self::assertFalse($this->facts($venue, guests: 150)->guestsFit);
    }

    public function test_a_room_whose_minimum_is_above_the_guests_does_not_fit(): void
    {
        $venue = $this->venue(min: 50, max: 100);
        RoomFactory::createOne(['venue' => $venue, 'capacityMin' => 20, 'capacityMax' => 40]);

        self::assertFalse($this->facts($venue, guests: 10)->guestsFit);
    }

    public function test_an_earlier_delivered_inquiry_from_the_same_address_in_any_case_is_found(): void
    {
        $venue = $this->venue();
        $this->earlier($venue, 'ANNA@x.ch', '2026-11-01 09:00:00 UTC');
        $this->earlier($venue, 'anna@x.ch', '2026-10-20 09:00:00 UTC');

        self::assertEquals(new \DateTimeImmutable('2026-11-01 09:00:00 UTC'), $this->facts($venue)->previousDeliveredAt);
    }

    public function test_failed_marked_later_foreign_and_own_rows_are_no_earlier_delivery(): void
    {
        $venue = $this->venue();
        $other = $this->venue();
        $this->earlier($venue, 'anna@x.ch', '2026-11-01 09:00:00 UTC', status: 'failed');
        $this->earlier($venue, 'anna@x.ch', '2026-11-02 09:00:00 UTC', override: 'spam');
        $this->earlier($venue, 'anna@x.ch', '2026-11-03 09:00:00 UTC', override: 'test');
        $this->earlier($venue, 'anna@x.ch', '2026-11-09 09:00:00 UTC');
        $this->earlier($other, 'anna@x.ch', '2026-11-04 09:00:00 UTC');
        $this->earlier($venue, 'berta@x.ch', '2026-11-05 09:00:00 UTC');
        $own = $this->earlier($venue, 'anna@x.ch', '2026-11-06 09:00:00 UTC');

        self::assertNull($this->facts($venue, id: $own)->previousDeliveredAt);
    }

    public function test_an_unknown_or_missing_venue_gives_permissive_facts(): void
    {
        $query = $this->query();

        foreach (['019a0000-0000-7000-8000-00000000f999', null] as $venueId) {
            $facts = $query->facts(self::ID, $venueId, 'anna@x.ch', new \DateTimeImmutable(self::CREATED), 5000, 'wedding', null);

            self::assertSame([], $facts->venueEventTypes);
            self::assertTrue($facts->guestsFit);
            self::assertNull($facts->previousDeliveredAt);
            self::assertFalse($facts->delivered);
        }
    }

    private function venue(?int $min = null, int $max = 120): Venue
    {
        return VenueFactory::new()->withContact()->create(['capacityMin' => $min, 'capacityMax' => $max]);
    }

    private function earlier(Venue $venue, string $email, string $createdAt, string $status = 'sent', ?string $override = null): string
    {
        $id = InquiryFactory::createOne(['venue' => $venue, 'email' => $email, 'createdAt' => new \DateTimeImmutable($createdAt)])->id();
        $this->connection->executeStatement(
            'UPDATE inquiries SET venue_mail_status = ?, qualification_override = ? WHERE id = ?',
            [$status, $override, $id],
        );

        return $id;
    }

    private function facts(Venue $venue, int $guests = 50, string $id = self::ID): QualificationFacts
    {
        return $this->query()->facts($id, $venue->id(), 'anna@x.ch', new \DateTimeImmutable(self::CREATED), $guests, 'seminar', null);
    }

    private function query(): QualificationFactsQuery
    {
        return new QualificationFactsQuery($this->connection);
    }
}
