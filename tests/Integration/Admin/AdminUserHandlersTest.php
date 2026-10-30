<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Command\ChangeAdminRole;
use App\Admin\Command\CreateAdminUser;
use App\Admin\Command\DeactivateAdminUser;
use App\Admin\Command\EnrolAdminTotp;
use App\Admin\Command\ReactivateAdminUser;
use App\Admin\Command\RecordAdminLogin;
use App\Admin\Command\ResetAdminPassword;
use App\Admin\Command\ResetAdminTotp;
use App\Admin\Entity\AdminUser;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\AdminIdentity;
use App\Admin\Security\TotpSecretCipher;
use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

final class AdminUserHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase;

    private const string PASSWORD = 'a-long-enough-password';

    public function test_create_hashes_the_password_and_lower_cases_the_email(): void
    {
        $id = EntityId::generate();

        $this->dispatch(new CreateAdminUser($id, 'New@Example.CH', 'Nora Neu', AdminRole::Editor, self::PASSWORD));

        $user = $this->reload($id);
        self::assertSame('new@example.ch', $user->email());
        self::assertSame(AdminRole::Editor, $user->role());
        self::assertNull($user->totpSecretEncrypted());
        self::assertNotSame(self::PASSWORD, $user->passwordHash());
        self::assertTrue($this->hashers()->getPasswordHasher(AdminIdentity::class)->verify($user->passwordHash(), self::PASSWORD));
    }

    public function test_create_rejects_a_duplicate_email(): void
    {
        AdminUserFactory::createOne(['email' => 'taken@example.ch']);

        self::assertSame(AdminUserRuleViolation::emailTaken()->translationKey(), $this->failure(
            new CreateAdminUser(EntityId::generate(), 'TAKEN@example.ch', 'Zweit', AdminRole::Editor, self::PASSWORD),
        )->translationKey());
    }

    public function test_create_rejects_a_short_password(): void
    {
        self::assertSame('users.error.password_too_short', $this->failure(
            new CreateAdminUser(EntityId::generate(), 'short@example.ch', 'Kurz', AdminRole::Editor, 'too-short'),
        )->translationKey());
    }

    public function test_change_role(): void
    {
        $user = AdminUserFactory::createOne();
        $actor = AdminUserFactory::createOne(['role' => AdminRole::Admin]);

        $this->dispatch(new ChangeAdminRole($user->id(), AdminRole::Admin, $actor->id()));

        self::assertSame(AdminRole::Admin, $this->reload($user->id())->role());
    }

    public function test_nobody_changes_their_own_role(): void
    {
        $actor = AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);

        self::assertSame('users.error.own_account', $this->failure(new ChangeAdminRole($actor->id(), AdminRole::Editor, $actor->id()))->translationKey());
    }

    public function test_the_last_active_admin_keeps_the_role(): void
    {
        $admin = AdminUserFactory::createOne(['role' => AdminRole::Admin]);

        self::assertSame('users.error.last_admin', $this->failure(new ChangeAdminRole($admin->id(), AdminRole::Editor, EntityId::generate()))->translationKey());
    }

    public function test_deactivate_and_reactivate(): void
    {
        $user = AdminUserFactory::createOne();
        $actor = AdminUserFactory::createOne(['role' => AdminRole::Admin]);

        $this->dispatch(new DeactivateAdminUser($user->id(), $actor->id()));
        self::assertFalse($this->reload($user->id())->isActive());

        $this->dispatch(new ReactivateAdminUser($user->id()));
        self::assertTrue($this->reload($user->id())->isActive());
    }

    public function test_nobody_deactivates_themselves(): void
    {
        $actor = AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);

        self::assertSame('users.error.own_account', $this->failure(new DeactivateAdminUser($actor->id(), $actor->id()))->translationKey());
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $admin = AdminUserFactory::createOne(['role' => AdminRole::Admin]);

        self::assertSame('users.error.last_admin', $this->failure(new DeactivateAdminUser($admin->id(), EntityId::generate()))->translationKey());
    }

    public function test_reset_password(): void
    {
        $user = AdminUserFactory::createOne();

        $this->dispatch(new ResetAdminPassword($user->id(), self::PASSWORD));

        self::assertTrue($this->hashers()->getPasswordHasher(AdminIdentity::class)->verify($this->reload($user->id())->passwordHash(), self::PASSWORD));
    }

    public function test_reset_password_rejects_a_short_one(): void
    {
        $user = AdminUserFactory::createOne();

        self::assertSame('users.error.password_too_short', $this->failure(new ResetAdminPassword($user->id(), 'short'))->translationKey());
    }

    public function test_enrol_stores_the_secret_encrypted_and_reset_clears_it(): void
    {
        $user = AdminUserFactory::createOne();

        $this->dispatch(new EnrolAdminTotp($user->id(), 'JBSWY3DPEHPK3PXP'));

        $encrypted = $this->reload($user->id())->totpSecretEncrypted();
        self::assertNotNull($encrypted);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $encrypted);
        $cipher = self::getContainer()->get(TotpSecretCipher::class);
        self::assertInstanceOf(TotpSecretCipher::class, $cipher);
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($encrypted));

        $this->dispatch(new ResetAdminTotp($user->id()));
        self::assertNull($this->reload($user->id())->totpSecretEncrypted());
    }

    public function test_record_login_uses_the_clock(): void
    {
        self::mockTime('2026-10-30 12:00:00 UTC');
        $user = AdminUserFactory::createOne();

        $this->dispatch(new RecordAdminLogin($user->id()));

        self::assertSame('2026-10-30T12:00:00+00:00', $this->reload($user->id())->lastLoginAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function test_an_unknown_user_is_a_rule_violation(): void
    {
        self::assertSame('users.error.not_found', $this->failure(new ResetAdminTotp(EntityId::generate()))->translationKey());
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($command);
    }

    private function failure(object $command): AdminUserRuleViolation
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return AdminUserRuleViolation::unwrap($failure);
        }

        self::fail('The command should have failed.');
    }

    private function reload(string $id): AdminUser
    {
        $this->em->clear();
        $users = self::getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $users);

        return $users->get($id);
    }

    private function hashers(): PasswordHasherFactoryInterface
    {
        $hashers = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hashers);

        return $hashers;
    }
}
