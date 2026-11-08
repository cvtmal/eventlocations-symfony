<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenuePreviewFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\IssueVenuePreview;
use App\Venues\Command\RevokeVenuePreview;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ReadModel\VenuePreviewQuery;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

final class VenuePreviewCommandsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesVenueCommands;
    use Factories;
    use RollsBackDatabase {
        setUp as private startTransaction;
    }

    protected function setUp(): void
    {
        self::mockTime('2026-11-07 10:00:00.6 UTC');
        $this->startTransaction();
    }

    public function test_issuing_stores_a_link_for_the_chosen_days_and_audits_no_secret(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);

        $id = $this->dispatch(new IssueVenuePreview($venue->number(), 'brief-2026-11', 30));

        self::assertIsString($id);
        $link = $this->previews()->find($id);
        self::assertNotNull($link);
        self::assertSame($venue->number(), $link->venueNumber);
        self::assertSame('brief-2026-11', $link->campaignCode);
        self::assertSame(new \DateTimeImmutable('2026-11-07 10:00:00 UTC')->getTimestamp(), $link->createdAt->getTimestamp(), 'whole seconds, not rounded up');
        self::assertSame(new \DateTimeImmutable('2026-12-07 10:00:00 UTC')->getTimestamp(), $link->expiresAt->getTimestamp());
        self::assertNull($link->revokedAt);
        self::assertSame('open', $link->state(new \DateTimeImmutable('2026-12-07 09:59:59 UTC')));
        self::assertSame('expired', $link->state(new \DateTimeImmutable('2026-12-07 10:00:00 UTC')));
        self::assertSame(
            [['command' => IssueVenuePreview::class, 'changes' => ['days' => 30, 'campaign' => 'brief-2026-11']]],
            $this->auditRows($venue->number()),
        );
    }

    public function test_the_handler_refuses_an_unplain_campaign_or_an_unknown_lifetime(): void
    {
        $venue = VenueFactory::createOne();

        foreach ([new IssueVenuePreview($venue->number(), 'jemand@example.ch'), new IssueVenuePreview($venue->number(), 'ok', 7)] as $command) {
            try {
                $this->dispatch($command);
                self::fail('The command should have failed.');
            } catch (HandlerFailedException $failure) {
                self::assertNotSame([], $failure->getWrappedExceptions(\InvalidArgumentException::class));
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_previews'));
    }

    public function test_a_missing_venue_is_refused(): void
    {
        $this->expectException(HandlerFailedException::class);
        try {
            $this->dispatch(new IssueVenuePreview(999999, 'ok'));
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(VenueNotFound::class));

            throw $failure;
        }
    }

    public function test_revoking_ends_the_link_once_and_another_venues_link_is_not_found(): void
    {
        $venue = VenueFactory::createOne();
        $preview = VenuePreviewFactory::createOne(['venue' => $venue]);
        $other = VenueFactory::createOne();

        $this->dispatch(new RevokeVenuePreview($venue->number(), $preview->id()));
        self::mockTime('+1 hour');
        $this->dispatch(new RevokeVenuePreview($venue->number(), $preview->id()));

        $link = $this->previews()->find($preview->id());
        self::assertNotNull($link);
        self::assertSame(new \DateTimeImmutable('2026-11-07 10:00:00 UTC')->getTimestamp(), $link->revokedAt?->getTimestamp(), 'the first revocation time stays');
        self::assertSame('revoked', $link->state(new \DateTimeImmutable('2026-11-07 10:00:00 UTC')));

        try {
            $this->dispatch(new RevokeVenuePreview($other->number(), $preview->id()));
            self::fail('The command should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(VenueRowNotFound::class));
        }
    }

    public function test_deleting_the_venue_deletes_its_links(): void
    {
        $venue = VenueFactory::createOne();
        $preview = VenuePreviewFactory::createOne(['venue' => $venue]);

        $this->connection->executeStatement('DELETE FROM venues WHERE id = ?', [$venue->id()]);

        self::assertNull($this->previews()->find($preview->id()));
    }

    public function test_a_short_code_is_unique_and_plain(): void
    {
        $a = VenuePreviewFactory::createOne();
        $b = VenuePreviewFactory::createOne();
        self::assertSame($a->id(), $this->previews()->findByShortCode((string) $a->shortCode())?->id);

        foreach ([
            'uk_venue_previews_short_code' => "UPDATE venue_previews SET short_code = '".$a->shortCode()."' WHERE id = '".$b->id()."'",
            'chk_venue_previews_short_code' => "UPDATE venue_previews SET short_code = 'k7m3qx9tpa' WHERE id = '".$b->id()."'",
        ] as $constraint => $sql) {
            $this->connection->executeStatement('SAVEPOINT short_code');
            try {
                $this->connection->executeStatement($sql);
                self::fail($constraint);
            } catch (\Doctrine\DBAL\Exception\DriverException $refused) {
                self::assertStringContainsString($constraint, $refused->getMessage());
            }
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT short_code');
        }
    }

    private function previews(): VenuePreviewQuery
    {
        $previews = self::getContainer()->get(VenuePreviewQuery::class);
        self::assertInstanceOf(VenuePreviewQuery::class, $previews);

        return $previews;
    }
}
