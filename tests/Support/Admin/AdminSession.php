<?php

declare(strict_types=1);

namespace App\Tests\Support\Admin;

use App\Admin\Entity\AdminUser;
use App\Admin\Security\AdminIdentity;
use App\Admin\Security\TotpSecretCipher;
use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Staff accounts and logins for application tests. The client shares the test's connection (no reboot), so
 * everything created here is visible to the requests and rolled back in tearDown.
 *
 * Secrets are random per user: the code-reuse cache is `cache.app`, which outlives a test.
 */
trait AdminSession
{
    private const string STAFF_PASSWORD = 'correct-horse-battery';

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

        $this->forgetThrottling();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    private function forgetThrottling(): void
    {
        $limiterCache = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limiterCache);
        $limiterCache->clear();
    }

    /**
     * @return array{user: AdminUser, secret: ?string}
     */
    private function createStaff(AdminRole $role = AdminRole::Admin, bool $enrolled = true, string $displayName = 'Andrea Keller'): array
    {
        $hashers = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hashers);

        $user = AdminUserFactory::createOne([
            'displayName' => $displayName,
            'role' => $role,
            'passwordHash' => $hashers->getPasswordHasher(AdminIdentity::class)->hash(self::STAFF_PASSWORD),
        ]);

        $secret = null;
        if ($enrolled) {
            $totp = self::getContainer()->get(TotpAuthenticatorInterface::class);
            self::assertInstanceOf(TotpAuthenticatorInterface::class, $totp);
            $cipher = self::getContainer()->get(TotpSecretCipher::class);
            self::assertInstanceOf(TotpSecretCipher::class, $cipher);

            $secret = $totp->generateSecret();
            $user->enrolTotp($cipher->encrypt($secret));
            $this->entityManager()->flush();
        }

        return ['user' => $user, 'secret' => $secret];
    }

    /**
     * A session as if the login had completed, for tests that are not about logging in.
     */
    private function logIn(AdminUser $user, ?string $secret): void
    {
        $this->client->loginUser(AdminIdentity::fromEntity($user, $secret), 'admin');
    }

    /**
     * @return array{user: AdminUser, secret: ?string}
     */
    private function logInAs(AdminRole $role = AdminRole::Admin, string $displayName = 'Andrea Keller'): array
    {
        $staff = $this->createStaff($role, displayName: $displayName);
        $this->logIn($staff['user'], $staff['secret']);

        return $staff;
    }

    private function submitPassword(string $email, string $password = self::STAFF_PASSWORD): void
    {
        // The GET first gives the POST a same-origin Referer, which the stateless CSRF check needs.
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Weiter', ['_username' => $email, '_password' => $password]);
    }

    private function submitCode(string $code): void
    {
        $this->client->request('GET', '/admin/2fa');
        $this->client->submitForm('Bestätigen', ['_auth_code' => $code]);
    }

    private function logInFully(AdminUser $user, string $secret): void
    {
        $this->submitPassword($user->email());
        $this->submitCode(self::totpCode($secret));
    }

    /**
     * The jar honours the cookie's `/admin` path like a browser, so it would not send it to `/%61dmin`. An
     * attacker's HTTP client does.
     */
    private function sendSessionCookieEverywhere(): void
    {
        $jar = $this->client->getCookieJar();
        foreach ($jar->all() as $cookie) {
            if ('/' !== $cookie->getPath()) {
                $jar->expire($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
                $jar->set(new Cookie($cookie->getName(), $cookie->getValue(), null, '/', (string) $cookie->getDomain()));
            }
        }
    }

    /**
     * Spellings of one path that Symfony's router and firewall read as that path: both apply rawurldecode().
     *
     * @return array<string, string> description => URL path
     */
    private static function encodedSpellings(string $path): array
    {
        $everyByte = static fn (string $format): string => '/'.implode('/', array_map(
            static fn (string $segment): string => implode('', array_map(
                static fn (string $byte): string => \sprintf($format, \ord($byte)),
                str_split($segment),
            )),
            explode('/', substr($path, 1)),
        ));

        $spellings = [
            'first letter encoded' => '/%61'.substr($path, 2),
            'every byte encoded, lower-case hex' => $everyByte('%%%02x'),
            'every byte encoded, upper-case hex' => $everyByte('%%%02X'),
        ];

        if ('/admin' !== $path) {
            $spellings['slash encoded'] = '/admin%2F'.substr($path, 7);
            $spellings['slash encoded, lower-case hex'] = '/admin%2f'.substr($path, 7);
            $spellings['every slash but the first encoded'] = '/'.str_replace('/', '%2F', substr($path, 1));
        }

        return array_unique($spellings);
    }

    private function assertNotCachedOrIndexed(string $message = ''): void
    {
        $headers = $this->client->getResponse()->headers;

        self::assertTrue($headers->hasCacheControlDirective('no-store'), trim($message.' has no-store.'));
        self::assertTrue($headers->hasCacheControlDirective('private'), trim($message.' is private.'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'), $message);
    }

    private static function totpCode(string $secret): string
    {
        \assert('' !== $secret);

        return TOTP::createFromSecret($secret, new NativeClock())->now();
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($command);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
