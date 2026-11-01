<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Exception\VenueNotFound;
use App\Venues\ValueObject\Tier;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tier on its own (D18): the ranking moves, the import is locked out, the listings are purged after the commit, and
 * the same tier is silent.
 */
final class ChangeVenueTierTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_the_tier_changes_with_its_trail(): void
    {
        self::getContainer()->set(ClockInterface::class, new MockClock('2026-11-01 10:00:00+01:00'));
        $venue = VenueFactory::createOne(['tier' => Tier::Basic]);
        self::assertNull($this->stamp($venue->number()));
        $purger = $this->purger();
        $purger->onListings = function () use ($venue): void {
            self::assertSame(1, \count($this->auditRows($venue->number())), 'The purge runs once the command is done.');
        };

        $this->dispatch(new ChangeVenueTier($venue->number(), Tier::Premium));

        self::assertSame(Tier::Premium, $this->reload($venue)->tier());
        self::assertSame(4, $this->reload($venue)->tierRank());
        self::assertSame('2026-11-01 09:00:00+00', $this->stamp($venue->number()));
        self::assertSame(
            [['command' => ChangeVenueTier::class, 'changes' => ['tier' => 'premium', 'changed' => ['tier']]]],
            $this->auditRows($venue->number()),
        );
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
    }

    public function test_the_same_tier_changes_nothing_and_purges_nothing(): void
    {
        $venue = VenueFactory::createOne(['tier' => Tier::Premium]);
        $purger = $this->purger();

        $this->dispatch(new ChangeVenueTier($venue->number(), Tier::Premium));

        self::assertNull($this->stamp($venue->number()));
        self::assertSame([], $purger->venues);
        self::assertSame(0, $purger->listings);
    }

    public function test_an_unknown_venue_is_a_domain_error(): void
    {
        try {
            $this->dispatch(new ChangeVenueTier(999999, Tier::Premium));
            self::fail('The command should have failed.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(VenueNotFound::class));
        }
    }

    private function stamp(int $number): ?string
    {
        $stamp = $this->connection->fetchOne("SELECT to_char(cms_edited_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS\"+00\"') FROM venues WHERE number = ?", [$number]);

        return false === $stamp ? null : (null === $stamp ? null : (string) $stamp);
    }
}
