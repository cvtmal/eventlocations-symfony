<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Helpers for the CRM command tests: dispatch on command.bus, the handler's exception, the audit
 * rows of a subject. Needs `$this->connection` and `$this->em` (RollsBackDatabase).
 */
trait DispatchesCrmCommands
{
    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * Dispatches a command that must fail and returns what its handler threw.
     *
     * @template T of \Throwable
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function failure(object $command, string $expected): \Throwable
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $thrown = array_values($failure->getWrappedExceptions())[0] ?? $failure;
            self::assertInstanceOf($expected, $thrown);

            return $thrown;
        }

        self::fail('The command should have failed.');
    }

    /**
     * @return list<array{command: string, changes: array<string, mixed>}>
     */
    private function auditRows(string $subjectType, string $subjectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT command, changes FROM admin_audit_log WHERE subject_type = ? AND subject_id = ? ORDER BY created_at, id',
            [$subjectType, $subjectId],
        );

        return array_map(static function (array $row): array {
            $changes = json_decode((string) $row['changes'], true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($changes));

            /* @var array<string, mixed> $changes */
            return ['command' => (string) $row['command'], 'changes' => $changes];
        }, $rows);
    }

    private function rows(string $sql, mixed ...$params): int
    {
        return (int) $this->connection->fetchOne($sql, array_values($params));
    }
}
