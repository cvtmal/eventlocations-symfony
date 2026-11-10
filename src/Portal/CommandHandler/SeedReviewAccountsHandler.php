<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\AdminIdentity;
use App\Admin\ValueObject\AdminRole;
use App\Portal\Command\SeedReviewAccounts;
use App\Portal\Entity\PortalUser;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Portal\Security\PortalIdentity;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Security\ReviewMode;
use App\Shared\Uid\EntityId;
use App\Venues\Repository\TierRepository;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * review-demo: creates or resets the review stack's admin and manager, both with the password `admin`. It writes the
 * entities directly with the configured hashers, past AdminPasswordHasher's minimum length and the forms' e-mail check,
 * which stay as they are for every other account. The manager gets the first published Free and Pro location, like the
 * dev manager of PortalStory, so /konto shows real demo data. Only in review mode, i.e. only on the review database.
 */
final readonly class SeedReviewAccountsHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReviewMode $reviewMode,
        private AdminUserRepository $admins,
        private PortalUserRepository $managers,
        private PortalVenueAccessRepository $access,
        private VenueRepository $venues,
        private TierRepository $tiers,
        private PasswordHasherFactoryInterface $hashers,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SeedReviewAccounts $command): void
    {
        if (!$this->reviewMode->isOn()) {
            throw new \LogicException('The review accounts exist only in review mode (APP_REVIEW_MODE=1 on the review stack).');
        }
        $now = $this->clock->now();

        $adminHash = $this->hashers->getPasswordHasher(AdminIdentity::class)->hash(ReviewMode::PASSWORD);
        $admin = $this->admins->findOneByEmail(ReviewMode::ADMIN_EMAIL);
        if (null === $admin) {
            $this->em->persist(new AdminUser(EntityId::generate(), ReviewMode::ADMIN_EMAIL, 'Review Admin', $adminHash, AdminRole::Admin));
        } else {
            $admin->changePassword($adminHash);
            $admin->changeRole(AdminRole::Admin);
            $admin->resetTotp();
            $admin->reactivate();
        }

        $manager = $this->managers->findOneByEmail(ReviewMode::MANAGER_EMAIL);
        if (null === $manager) {
            $manager = new PortalUser(EntityId::generate(), ReviewMode::MANAGER_EMAIL, 'Review', 'Manager');
            $this->em->persist($manager);
        }
        $manager->setPasswordFromMail($this->hashers->getPasswordHasher(PortalIdentity::class)->hash(ReviewMode::PASSWORD), $now);
        $manager->reactivate();

        foreach ([KnownTiers::FREE, KnownTiers::PRO] as $code) {
            $venue = $this->venues->findOneBy(['tier' => $this->tiers->byCode($code), 'status' => VenueStatus::Published], ['number' => 'ASC']);
            if (null === $venue) {
                continue;
            }
            $access = $this->access->findAccess($manager->id(), $venue->id());
            if (null === $access) {
                $access = new PortalVenueAccess($manager, $venue, $now);
                $this->em->persist($access);
            }
            $access->confirm($now);
        }

        $this->em->flush();
    }
}
