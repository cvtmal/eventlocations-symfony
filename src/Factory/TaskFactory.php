<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Task;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;

/**
 * An open task due in a week, on no object, assigned to a new editor.
 *
 * @extends PersistentObjectFactory<Task>
 */
final class TaskFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Task::class;
    }

    public function done(\DateTimeImmutable $at): self
    {
        return $this->afterInstantiate(static fn (Task $task) => $task->complete($at));
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'title' => self::faker()->randomElement(['Offerte nachfassen', 'Vertrag senden', 'Besichtigung planen', 'Fotos anfragen', 'Rückruf']),
            'dueOn' => new \DateTimeImmutable('today +7 days'),
            'assignee' => lazy(static fn () => AdminUserFactory::createOne()),
            'createdBy' => null,
            'company' => null,
        ];
    }
}
