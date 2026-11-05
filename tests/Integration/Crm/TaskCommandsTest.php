<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\Entity\AdminUser;
use App\Crm\Command\CompleteTask;
use App\Crm\Command\CreateTask;
use App\Crm\Command\ReopenTask;
use App\Crm\Entity\Task;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\TaskNotFound;
use App\Crm\ValueObject\RecordKind;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * CRM-5, D8, D20: a task on a record or on none, done and undone, and an audit row that never holds the title.
 */
final class TaskCommandsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    private const string DUE = '2026-11-03';

    public function test_a_task_on_a_company_has_that_company(): void
    {
        $author = AdminUserFactory::createOne();
        $assignee = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateTask($id, "  Offerte\r\nsenden ", new \DateTimeImmutable(self::DUE), $assignee->id(), $author->id(), RecordKind::Company, $company->id()));

        $this->em->clear();
        $task = $this->em->find(Task::class, $id);
        self::assertNotNull($task);
        self::assertSame("Offerte\nsenden", $task->title());
        self::assertSame(self::DUE, $task->dueOn()->format('Y-m-d'));
        self::assertSame($assignee->id(), $task->assignee()->id());
        self::assertSame($author->id(), $task->createdBy()?->id());
        self::assertSame($company->id(), $task->company()?->id());
        self::assertNull($task->deal(), 'D8: a task has one object at most.');
        self::assertFalse($task->isDone());
    }

    public function test_a_task_from_the_list_is_on_no_record(): void
    {
        $author = AdminUserFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateTask($id, 'Ablage', new \DateTimeImmutable(self::DUE), $author->id(), $author->id()));

        $this->em->clear();
        $task = $this->em->find(Task::class, $id);
        self::assertNotNull($task);
        self::assertNull($task->company());
        self::assertNull($task->deal());
    }

    public function test_the_audit_rows_never_hold_the_title(): void
    {
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateTask($id, 'Vertraulich', new \DateTimeImmutable(self::DUE), $author->id(), $author->id(), RecordKind::Company, $company->id()));
        $this->dispatch(new CompleteTask($id));
        $this->dispatch(new ReopenTask($id));

        self::assertSame([
            ['command' => CreateTask::class, 'changes' => ['on' => 'company']],
            ['command' => CompleteTask::class, 'changes' => ['action' => 'completed']],
            ['command' => ReopenTask::class, 'changes' => ['action' => 'reopened']],
        ], $this->auditRows('task', $id));
        self::assertSame(0, $this->rows("SELECT count(*) FROM admin_audit_log WHERE changes::text LIKE '%Vertraulich%'"));
    }

    public function test_a_blank_title_is_refused(): void
    {
        $author = AdminUserFactory::createOne();

        $thrown = $this->failure(new CreateTask(EntityId::generate(), "  \n ", new \DateTimeImmutable(self::DUE), $author->id(), $author->id()), InvalidPropertyValue::class);

        self::assertSame('title', $thrown->field);
        self::assertTrue($thrown->isRequired());
    }

    public function test_a_task_cannot_go_to_a_deactivated_account_or_nobody(): void
    {
        $author = AdminUserFactory::createOne();
        $gone = AdminUserFactory::new()->afterInstantiate(static fn (AdminUser $user) => $user->deactivate())->create();

        $thrown = $this->failure(new CreateTask(EntityId::generate(), 'Rückruf', new \DateTimeImmutable(self::DUE), $gone->id(), $author->id()), InvalidPropertyValue::class);
        self::assertSame('owner', $thrown->field);
        self::assertFalse($thrown->isRequired());

        $this->failure(new CreateTask(EntityId::generate(), 'Rückruf', new \DateTimeImmutable(self::DUE), EntityId::generate(), $author->id()), InvalidPropertyValue::class);
    }

    public function test_a_missing_record_is_refused(): void
    {
        $author = AdminUserFactory::createOne();

        $this->failure(new CreateTask(EntityId::generate(), 'Rückruf', new \DateTimeImmutable(self::DUE), $author->id(), $author->id(), RecordKind::Company, EntityId::generate()), CompanyNotFound::class);
    }

    public function test_done_keeps_the_moment_of_the_clock_and_undone_clears_it(): void
    {
        self::mockTime('2026-10-31 09:30:00 UTC');
        $task = TaskFactory::createOne();

        $this->dispatch(new CompleteTask($task->id()));

        $this->em->clear();
        $done = $this->em->find(Task::class, $task->id());
        self::assertNotNull($done);
        self::assertTrue($done->isDone());
        self::assertSame((new \DateTimeImmutable('2026-10-31 09:30:00 UTC'))->getTimestamp(), $done->doneAt()?->getTimestamp());

        $this->dispatch(new ReopenTask($task->id()));

        $this->em->clear();
        self::assertFalse($this->em->find(Task::class, $task->id())?->isDone());
    }

    public function test_done_twice_keeps_the_first_moment(): void
    {
        self::mockTime('2026-10-31 09:30:00 UTC');
        $first = new \DateTimeImmutable('2026-10-30 08:00:00 UTC');
        $task = TaskFactory::new()->done($first)->create();

        $this->dispatch(new CompleteTask($task->id()));

        $this->em->clear();
        self::assertSame($first->getTimestamp(), $this->em->find(Task::class, $task->id())?->doneAt()?->getTimestamp());
    }

    public function test_a_gone_task_is_refused(): void
    {
        self::assertSame('nope', $this->failure(new CompleteTask('nope'), TaskNotFound::class)->taskId);
        $this->failure(new ReopenTask(EntityId::generate()), TaskNotFound::class);
    }
}
