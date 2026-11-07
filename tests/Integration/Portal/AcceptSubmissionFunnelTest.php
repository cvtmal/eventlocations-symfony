<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Admin\Exception\AdminUserRuleViolation;
use App\Factory\AdminUserFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Command\AcceptSubmission;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G8: the portal's accept publishes through a nested `PublishVenue`, whose funnel step waits for the outer command.
 * It is written once the accept committed, and it goes with the accept when that rolls back after the publish.
 */
final class AcceptSubmissionFunnelTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_an_accepted_submission_records_the_publication_after_the_command(): void
    {
        $venue = $this->submitted();
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->bus()->dispatch(new AcceptSubmission($venue->number(), AdminUserFactory::createOne()->id()));

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'venue_published' AND venue_id = ?", [$venue->id()]));
    }

    public function test_a_failure_after_the_publish_takes_the_funnel_step_with_it(): void
    {
        $venue = $this->submitted();
        $this->connection->executeStatement('DELETE FROM funnel_events');

        try {
            // The reviewer does not exist: the accept fails after PublishVenue went through.
            $this->bus()->dispatch(new AcceptSubmission($venue->number(), EntityId::generate()));
            self::fail('The accept should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(AdminUserRuleViolation::class, $failure->getPrevious(), 'it failed at the reviewer, after the publish');
        }

        self::assertSame('unpublished', $this->connection->fetchOne('SELECT status FROM venues WHERE id = ?', [$venue->id()]), 'the publish was rolled back');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'), 'so no step was recorded');
    }

    private function submitted(): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => 'Ein Ort für den Anlass.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        return $venue;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
