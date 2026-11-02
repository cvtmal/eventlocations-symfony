<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Admin\Security\AdminIdentity;
use App\Factory\AdminUserFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Entity\PortalUser;
use App\Portal\ReadModel\PortalVenueAccessQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Zenstruck\Foundry\Test\Factories;

final class PortalVenueVoterTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_no_row_denies(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->identity($manager), $venue->number()));
    }

    public function test_pending_denies(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->identity($manager), $venue->number()));
    }

    public function test_another_managers_active_row_denies(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->identity($manager), $venue->number()));
    }

    public function test_an_active_row_on_another_venue_denies(): void
    {
        $manager = PortalUserFactory::createOne();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $this->venue()]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->identity($manager), $this->venue()->number()));
    }

    public function test_active_grants_for_an_int_and_for_a_digit_string(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        $identity = $this->identity($manager);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($identity, $venue->number()));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($identity, (string) $venue->number()));
    }

    public function test_staff_are_denied(): void
    {
        $staff = AdminUserFactory::createOne();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(AdminIdentity::fromEntity($staff, null), $this->venue()->number()));
    }

    public function test_an_unrelated_attribute_abstains(): void
    {
        $identity = $this->identity(PortalUserFactory::createOne());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($identity, 123, 'SOMETHING_ELSE'));
    }

    public function test_a_subject_that_is_no_number_abstains(): void
    {
        $identity = $this->identity(PortalUserFactory::createOne());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($identity, 'x1'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($identity, null));
    }

    private function vote(UserInterface $user, mixed $subject, string $attribute = PortalVenueVoter::EDIT): int
    {
        $query = self::getContainer()->get(PortalVenueAccessQuery::class);
        self::assertInstanceOf(PortalVenueAccessQuery::class, $query);

        return (new PortalVenueVoter($query))->vote(new UsernamePasswordToken($user, 'portal', $user->getRoles()), $subject, [$attribute]);
    }

    private function identity(PortalUser $manager): PortalIdentity
    {
        return PortalIdentity::fromEntity($manager);
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create();
    }
}
