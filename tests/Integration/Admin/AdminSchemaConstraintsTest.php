<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One rejection per CHECK of the back-office tables: the third validation layer, without the PHP above it.
 */
final class AdminSchemaConstraintsTest extends KernelTestCase
{
    use RollsBackDatabase;

    /**
     * @return iterable<string, array{string, array<string, string|null>}>
     */
    public static function invalidUsers(): iterable
    {
        yield 'upper-case e-mail' => ['chk_admin_users_email', ['email' => 'Anna@example.ch']];
        yield 'unknown role' => ['chk_admin_users_role', ['role' => 'chef']];
        yield 'blank name' => ['chk_admin_users_display_name', ['display_name' => '   ']];
    }

    /**
     * @param array<string, string|null> $override
     */
    #[DataProvider('invalidUsers')]
    public function test_a_user_row_is_refused(string $constraint, array $override): void
    {
        $this->expectViolationOf($constraint);

        $this->user($override);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->user(['email' => 'anna@example.ch']);

        $this->expectViolationOf('uk_admin_users_email');

        $this->user(['email' => 'anna@example.ch']);
    }

    public function test_an_unknown_actor_type_is_refused(): void
    {
        $this->expectViolationOf('chk_admin_audit_log_actor_type');

        $this->entry(['actor_type' => 'robot']);
    }

    public function test_an_admin_user_entry_needs_an_actor(): void
    {
        $this->expectViolationOf('chk_admin_audit_log_actor');

        $this->entry(['actor_type' => 'admin_user']);
    }

    public function test_a_console_entry_has_no_actor(): void
    {
        $id = $this->user();

        $this->expectViolationOf('chk_admin_audit_log_actor');

        $this->entry(['actor_type' => 'console', 'actor_id' => $id]);
    }

    public function test_changes_must_be_an_object(): void
    {
        $this->expectViolationOf('chk_admin_audit_log_changes');

        $this->entry(['changes' => '[1, 2]']);
    }

    public function test_a_user_with_audit_entries_cannot_be_deleted(): void
    {
        $id = $this->user();
        $this->entry(['actor_type' => 'admin_user', 'actor_id' => $id]);

        $this->expectViolationOf('fk_admin_audit_log_actor_id');

        $this->connection->delete('admin_users', ['id' => $id]);
    }

    public function test_a_portal_entry_needs_no_actor_id(): void
    {
        $id = $this->manager();

        $this->entry(['actor_type' => 'portal_user', 'portal_user_id' => $id]);

        self::assertSame($id, $this->connection->fetchOne("SELECT portal_user_id FROM admin_audit_log WHERE actor_type = 'portal_user'"));
    }

    public function test_only_a_portal_entry_names_a_manager(): void
    {
        $id = $this->manager();

        $this->expectViolationOf('chk_admin_audit_log_portal_user');

        $this->entry(['actor_type' => 'system', 'portal_user_id' => $id]);
    }

    public function test_deleting_a_manager_keeps_the_entry(): void
    {
        $id = $this->manager();
        $this->entry(['actor_type' => 'portal_user', 'portal_user_id' => $id]);

        $this->connection->delete('portal_users', ['id' => $id]);

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE actor_type = 'portal_user' AND portal_user_id IS NULL"));
    }

    public function test_a_manager_with_an_upper_case_email_is_refused(): void
    {
        $this->expectViolationOf('chk_portal_users_email');

        $this->manager('Anna@example.ch');
    }

    private function expectViolationOf(string $constraint): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/'.$constraint.'/');
    }

    /**
     * @param array<string, string|null> $override
     */
    private function user(array $override = []): string
    {
        $id = EntityId::generate();
        $this->connection->insert('admin_users', $override + [
            'id' => $id,
            'email' => $id.'@example.ch',
            'display_name' => 'Anna',
            'password_hash' => '!',
            'role' => 'editor',
        ]);

        return $id;
    }

    private function manager(?string $email = null): string
    {
        $id = EntityId::generate();
        $this->connection->insert('portal_users', [
            'id' => $id,
            'email' => $email ?? $id.'@example.ch',
            'password_hash' => '!',
            'first_name' => 'Anna',
            'last_name' => 'Muster',
        ]);

        return $id;
    }

    /**
     * @param array<string, string|null> $override
     */
    private function entry(array $override): void
    {
        $this->connection->insert('admin_audit_log', $override + [
            'id' => EntityId::generate(),
            'actor_type' => 'console',
            'actor_id' => null,
            'command' => 'App\Probe',
            'subject_type' => 'probe',
            'subject_id' => 'x',
            'changes' => '{}',
        ]);
    }
}
