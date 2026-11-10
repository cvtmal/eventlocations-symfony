<?php

declare(strict_types=1);

namespace App\Tests\Support\Portal;

use App\Admin\Repository\AdminUserRepository;
use App\Portal\Command\SeedReviewAccounts;
use App\Portal\CommandHandler\SeedReviewAccountsHandler;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Shared\Security\ReviewMode;
use App\Venues\Repository\TierRepository;
use App\Venues\Repository\VenueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * review-demo: the two review accounts as `app:review:seed-accounts` writes them, and review mode switched on for the
 * test's container. The test env's own APP_REVIEW_MODE stays 0: App\Kernel would refuse it on app_test.
 */
trait ReviewAccounts
{
    /** Writes the accounts through the real handler, as review mode would; the container's own flag is untouched. */
    private function seedReviewAccounts(): void
    {
        $container = self::getContainer();
        $handler = new SeedReviewAccountsHandler(
            new ReviewMode(true),
            $this->service($container->get(AdminUserRepository::class), AdminUserRepository::class),
            $this->service($container->get(PortalUserRepository::class), PortalUserRepository::class),
            $this->service($container->get(PortalVenueAccessRepository::class), PortalVenueAccessRepository::class),
            $this->service($container->get(VenueRepository::class), VenueRepository::class),
            $this->service($container->get(TierRepository::class), TierRepository::class),
            $this->service($container->get(PasswordHasherFactoryInterface::class), PasswordHasherFactoryInterface::class),
            $this->service($container->get(EntityManagerInterface::class), EntityManagerInterface::class),
            $this->service($container->get(ClockInterface::class), ClockInterface::class),
        );
        $handler(new SeedReviewAccounts());
    }

    /** Before the first request: every service that asks ReviewMode gets this one. */
    private function switchReviewModeOn(): void
    {
        self::getContainer()->set(ReviewMode::class, new ReviewMode(true));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(mixed $service, string $class): object
    {
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
