<?php

declare(strict_types=1);

namespace App\Tests\Support\Portal;

use App\Factory\PortalUserFactory;
use App\Portal\Entity\PortalUser;
use App\Portal\Security\PortalIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Manager accounts and logins for application tests. Everything posts the real form: `loginUser()` runs
 * outside a request, so its session would be MOCKSESSID and not KONTOSESSID. The client shares the test's
 * connection (no reboot), so everything created here is visible to the requests and rolled back in tearDown.
 *
 * Beside AdminSession in one class, name Admin's setUp and tearDown the winners, as PortalAdminSeparationTest does.
 */
trait PortalSession
{
    private const string MANAGER_PASSWORD = 'correct-horse-staple';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient(server: ['REMOTE_ADDR' => '10.20.30.40']);
        $this->client->disableReboot();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        $this->forgetPortalThrottling();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    private function forgetPortalThrottling(): void
    {
        foreach (['cache.rate_limiter', 'cache.portal_register'] as $id) {
            $limiterCache = self::getContainer()->get($id);
            self::assertInstanceOf(CacheItemPoolInterface::class, $limiterCache);
            $limiterCache->clear();
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createManager(bool $confirmed = true, bool $active = true, array $attributes = []): PortalUser
    {
        $hashers = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hashers);

        $factory = $confirmed
            ? PortalUserFactory::new()->with(['passwordHash' => $hashers->getPasswordHasher(PortalIdentity::class)->hash(self::MANAGER_PASSWORD)])
            : PortalUserFactory::new()->unconfirmed();
        if (!$active) {
            $factory = $factory->deactivated();
        }

        return $factory->create($attributes);
    }

    private function submitManagerLogin(string $email, string $password = self::MANAGER_PASSWORD): void
    {
        // The GET first gives the POST a same-origin Referer, which the stateless CSRF check needs.
        $this->client->request('GET', '/konto/anmelden');
        $this->client->submitForm('Anmelden', ['_username' => $email, '_password' => $password]);
    }

    private function logInAsManager(?PortalUser $manager = null): PortalUser
    {
        $manager ??= $this->createManager();
        $this->submitManagerLogin($manager->email());

        return $manager;
    }

    private function portalEntityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
