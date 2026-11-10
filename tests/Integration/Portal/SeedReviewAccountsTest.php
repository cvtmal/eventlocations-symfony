<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Admin\Security\AdminIdentity;
use App\Factory\VenueFactory;
use App\Portal\Security\PortalIdentity;
use App\Shared\Security\ReviewMode;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Portal\ReviewAccounts;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * review-demo: `app:review:seed-accounts` writes the admin and the manager behind `admin` / `admin`, and only in review
 * mode, so these accounts can only ever reach the review stack's database.
 */
final class SeedReviewAccountsTest extends KernelTestCase
{
    use Factories;
    use ReviewAccounts;
    use RollsBackDatabase;

    public function test_the_command_refuses_outside_review_mode_and_writes_nothing(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:review:seed-accounts'));

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('only in review mode', $tester->getDisplay());
        self::assertSame(0, $this->accounts());
    }

    public function test_the_command_seeds_both_accounts_in_review_mode(): void
    {
        $this->switchReviewModeOn();
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:review:seed-accounts'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(2, $this->accounts());
    }

    public function test_the_accounts_take_admin_as_password_the_manager_is_confirmed_and_runs_free_and_pro(): void
    {
        $free = VenueFactory::createOne(['name' => 'Free Review', 'tier' => KnownTiers::FREE]);
        $pro = VenueFactory::createOne(['name' => 'Pro Review', 'tier' => KnownTiers::PRO]);

        $this->seedReviewAccounts();

        $admin = $this->connection->fetchAssociative('SELECT role, is_active, password_hash, totp_secret_encrypted FROM admin_users WHERE email = ?', [ReviewMode::ADMIN_EMAIL]);
        self::assertIsArray($admin);
        self::assertSame('admin', $admin['role']);
        self::assertTrue($admin['is_active']);
        self::assertNull($admin['totp_secret_encrypted']);
        self::assertTrue($this->verifies(AdminIdentity::class, (string) $admin['password_hash']));

        $manager = $this->connection->fetchAssociative('SELECT id, is_active, password_hash, email_verified_at FROM portal_users WHERE email = ?', [ReviewMode::MANAGER_EMAIL]);
        self::assertIsArray($manager);
        self::assertTrue($manager['is_active']);
        self::assertNotNull($manager['email_verified_at']);
        self::assertTrue($this->verifies(PortalIdentity::class, (string) $manager['password_hash']));
        self::assertEqualsCanonicalizing(
            [$free->id(), $pro->id()],
            $this->connection->fetchFirstColumn("SELECT venue_id FROM portal_venue_access WHERE portal_user_id = ? AND status = 'active'", [$manager['id']]),
        );
    }

    public function test_a_second_run_resets_the_accounts_instead_of_adding_any(): void
    {
        VenueFactory::createOne(['tier' => KnownTiers::PRO]);
        $this->seedReviewAccounts();
        $this->connection->executeStatement("UPDATE admin_users SET is_active = false, password_hash = 'x', totp_secret_encrypted = 'y', role = 'editor' WHERE email = ?", [ReviewMode::ADMIN_EMAIL]);
        $this->connection->executeStatement("UPDATE portal_users SET is_active = false, password_hash = 'x' WHERE email = ?", [ReviewMode::MANAGER_EMAIL]);
        $this->em->clear();

        $this->seedReviewAccounts();

        self::assertSame(2, $this->accounts());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM portal_venue_access a JOIN portal_users u ON u.id = a.portal_user_id WHERE u.email = ?', [ReviewMode::MANAGER_EMAIL]));
        $admin = $this->connection->fetchAssociative('SELECT role, is_active, password_hash, totp_secret_encrypted FROM admin_users WHERE email = ?', [ReviewMode::ADMIN_EMAIL]);
        self::assertIsArray($admin);
        self::assertSame(['admin', true, null], [$admin['role'], $admin['is_active'], $admin['totp_secret_encrypted']]);
        self::assertTrue($this->verifies(AdminIdentity::class, (string) $admin['password_hash']));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM portal_users WHERE email = ?', [ReviewMode::MANAGER_EMAIL]));
    }

    /**
     * @param class-string $identity
     */
    private function verifies(string $identity, string $hash): bool
    {
        $hashers = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hashers);

        return $hashers->getPasswordHasher($identity)->verify($hash, ReviewMode::PASSWORD);
    }

    private function accounts(): int
    {
        return (int) $this->connection->fetchOne('SELECT (SELECT COUNT(*) FROM admin_users WHERE email = ?) + (SELECT COUNT(*) FROM portal_users WHERE email = ?)', [ReviewMode::ADMIN_EMAIL, ReviewMode::MANAGER_EMAIL]);
    }
}
