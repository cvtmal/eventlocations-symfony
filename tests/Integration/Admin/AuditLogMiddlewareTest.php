<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Security\AdminIdentity;
use App\Factory\AdminUserFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Fixtures\Admin\AdminExtensionProbeKernel;
use App\Tests\Fixtures\Admin\AuditProbe\AuditProbeCommand;
use App\Tests\Fixtures\Admin\AuditProbe\PlainProbeCommand;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

final class AuditLogMiddlewareTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new AdminExtensionProbeKernel('test', true);
    }

    public function test_a_command_from_the_console_is_recorded_as_console(): void
    {
        $id = EntityId::generate();

        $this->dispatch(new AuditProbeCommand($id));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('console', $rows[0]['actor_type']);
        self::assertNull($rows[0]['actor_id']);
        self::assertSame(AuditProbeCommand::class, $rows[0]['command']);
        self::assertSame('probe', $rows[0]['subject_type']);
        self::assertSame($id, $rows[0]['subject_id']);
        self::assertIsString($rows[0]['changes']);
        self::assertEquals(['name' => 'Probe', 'tags' => ['a', 'b'], 'count' => 2, 'gone' => null], json_decode($rows[0]['changes'], true));
    }

    public function test_a_logged_in_user_is_the_actor(): void
    {
        $user = AdminUserFactory::createOne();
        $identity = AdminIdentity::fromEntity($user, null);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($identity, 'admin', $identity->getRoles()));

        $this->dispatch(new AuditProbeCommand(EntityId::generate()));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('admin_user', $rows[0]['actor_type']);
        self::assertSame($user->id(), $rows[0]['actor_id']);
    }

    public function test_a_web_request_without_staff_is_recorded_as_system(): void
    {
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/hochzeitslocations'));

        $this->dispatch(new AuditProbeCommand(EntityId::generate()));

        self::assertSame('system', $this->rows()[0]['actor_type']);
    }

    public function test_a_failing_handler_leaves_neither_the_change_nor_a_row(): void
    {
        $id = EntityId::generate();

        try {
            $this->dispatch(new AuditProbeCommand($id, fail: true));
            self::fail('The handler should have failed.');
        } catch (HandlerFailedException) {
        }

        self::assertSame([], $this->rows());
        self::assertFalse($this->connection->fetchOne('SELECT id FROM admin_users WHERE id = ?', [$id]));
    }

    public function test_a_plain_command_writes_no_row(): void
    {
        $this->dispatch(new PlainProbeCommand(EntityId::generate()));

        self::assertSame([], $this->rows());
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($command);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT actor_type, actor_id, command, subject_type, subject_id, changes FROM admin_audit_log');
    }
}
