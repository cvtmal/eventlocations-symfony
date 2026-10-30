<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Factory\AdminUserFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

final class AdminConsoleCommandsTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    private const string PASSWORD = 'a-long-enough-password';

    public function test_create_user_writes_the_user_and_a_console_audit_row(): void
    {
        $tester = $this->tester('app:admin:create-user');
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $tester->execute(['email' => 'Neu@Example.ch', '--role' => 'admin', '--name' => 'Nora Neu']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $row = $this->connection->fetchAssociative("SELECT id, role, display_name FROM admin_users WHERE email = 'neu@example.ch'");
        self::assertIsArray($row);
        self::assertSame('admin', $row['role']);
        self::assertSame('Nora Neu', $row['display_name']);

        $audit = $this->connection->fetchAssociative('SELECT actor_type, actor_id FROM admin_audit_log WHERE subject_id = ?', [$row['id']]);
        self::assertIsArray($audit);
        self::assertSame('console', $audit['actor_type']);
        self::assertNull($audit['actor_id']);
    }

    public function test_the_role_defaults_to_editor_and_the_name_to_the_mailbox(): void
    {
        $tester = $this->tester('app:admin:create-user');
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $tester->execute(['email' => 'nora@example.ch']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['role' => 'editor', 'display_name' => 'nora'], $this->connection->fetchAssociative("SELECT role, display_name FROM admin_users WHERE email = 'nora@example.ch'"));
    }

    public function test_differing_passwords_fail(): void
    {
        $tester = $this->tester('app:admin:create-user');
        $tester->setInputs([self::PASSWORD, 'something-else-entirely']);

        $tester->execute(['email' => 'neu@example.ch']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->users());
    }

    public function test_a_short_password_fails_with_the_catalogue_text(): void
    {
        $tester = $this->tester('app:admin:create-user');
        $tester->setInputs(['kurz', 'kurz']);

        $tester->execute(['email' => 'neu@example.ch']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('mindestens 12 Zeichen', $tester->getDisplay());
        self::assertSame(0, $this->users());
    }

    public function test_an_unknown_role_fails(): void
    {
        $tester = $this->tester('app:admin:create-user');

        $tester->execute(['email' => 'neu@example.ch', '--role' => 'chef']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function test_an_invalid_email_fails(): void
    {
        $tester = $this->tester('app:admin:create-user');

        $tester->execute(['email' => 'kein-mail']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function test_a_duplicate_email_fails(): void
    {
        AdminUserFactory::createOne(['email' => 'neu@example.ch']);
        $tester = $this->tester('app:admin:create-user');
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $tester->execute(['email' => 'NEU@example.ch']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('schon verwendet', $tester->getDisplay());
    }

    public function test_reset_2fa_clears_the_secret_and_is_audited_as_console(): void
    {
        $user = AdminUserFactory::createOne(['email' => 'neu@example.ch']);
        $user->enrolTotp('v1:x');
        $this->em->flush();
        $tester = $this->tester('app:admin:reset-2fa');

        $tester->execute(['email' => 'NEU@example.ch']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNull($this->connection->fetchOne('SELECT totp_secret_encrypted FROM admin_users WHERE id = ?', [$user->id()]));
        self::assertSame('console', $this->connection->fetchOne('SELECT actor_type FROM admin_audit_log WHERE subject_id = ?', [$user->id()]));
    }

    public function test_reset_password_changes_the_hash(): void
    {
        $user = AdminUserFactory::createOne(['email' => 'neu@example.ch']);
        $tester = $this->tester('app:admin:reset-password');
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $tester->execute(['email' => 'neu@example.ch']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotSame('!', $this->connection->fetchOne('SELECT password_hash FROM admin_users WHERE id = ?', [$user->id()]));
    }

    public function test_an_unknown_email_fails(): void
    {
        foreach (['app:admin:reset-2fa', 'app:admin:reset-password'] as $command) {
            $tester = $this->tester($command);

            $tester->execute(['email' => 'nobody@example.ch']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $command);
        }
    }

    private function tester(string $name): CommandTester
    {
        // The booted kernel: a new one would open a second connection outside the test's transaction.
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find($name));
    }

    private function users(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_users');
    }
}
