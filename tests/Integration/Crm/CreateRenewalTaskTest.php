<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\ChangeDealProperty;
use App\Crm\Command\CreateRenewalTask;
use App\Crm\Command\MoveDealStage;
use App\Crm\Entity\Deal;
use App\Crm\Entity\Task;
use App\Crm\Exception\NoTaskAssignee;
use App\Crm\ValueObject\DealProperty;
use App\Crm\ValueObject\LostReason;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * D24: one task per deal, for good. The assignee chain, the due date's clamp, what the scope re-check skips, and how
 * an open task follows its deal.
 */
final class CreateRenewalTaskTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_writes_the_task_on_the_deal_due_30_days_before_the_end(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $owner = $this->staff();
        $deal = $this->ending('2026-12-31', ['name' => 'Premium 2026', 'owner' => $owner]);
        $id = EntityId::generate();

        self::assertTrue($this->dispatch(new CreateRenewalTask($id, $deal->id())));

        self::assertSame(
            ['title' => 'Verlängerung: Premium 2026', 'due_on' => '2026-12-01', 'assignee_id' => $owner->id(), 'created_by_id' => null, 'deal_id' => $deal->id(), 'is_renewal' => true, 'company_id' => null],
            $this->connection->fetchAssociative('SELECT title, due_on, assignee_id, created_by_id, deal_id, is_renewal, company_id FROM crm_tasks WHERE id = ?', [$id]),
        );
        self::assertSame([['command' => CreateRenewalTask::class, 'changes' => ['on' => 'deal', 'renewal' => true]]], $this->auditRows('task', $id));
    }

    public function test_a_due_date_already_past_is_today(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-11-20', ['owner' => $this->staff()]);
        $id = EntityId::generate();

        $this->dispatch(new CreateRenewalTask($id, $deal->id()));

        self::assertSame('2026-11-01', $this->connection->fetchOne('SELECT due_on FROM crm_tasks WHERE id = ?', [$id]));
    }

    public function test_an_inactive_deal_owner_falls_to_the_company_owner(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $companyOwner = $this->staff();
        $deal = $this->ending('2026-12-31', [
            'owner' => $this->staff(active: false),
            'company' => CompanyFactory::createOne(['owner' => $companyOwner]),
        ]);

        self::assertSame($companyOwner->id(), $this->assigneeOf($deal));
    }

    public function test_without_an_owner_the_oldest_active_admin_takes_it(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $this->staff(AdminRole::Editor);
        $older = $this->staff(AdminRole::Admin);
        $this->staff(AdminRole::Admin);
        $this->staff(AdminRole::Admin, active: false);
        $this->connection->executeStatement("UPDATE admin_users SET created_at = '2020-01-01' WHERE id = ?", [$older->id()]);

        self::assertSame($older->id(), $this->assigneeOf($this->ending('2026-12-31', ['company' => CompanyFactory::createOne(['owner' => $this->staff(active: false)])])));
    }

    public function test_with_nobody_active_it_fails_and_writes_nothing(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $deal = $this->ending('2026-12-31');

        $this->failure(new CreateRenewalTask(EntityId::generate(), $deal->id()), NoTaskAssignee::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', $deal->id()));
    }

    public function test_a_done_task_is_not_written_again(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31', ['owner' => $this->staff()]);
        $this->dispatch(new CreateRenewalTask(EntityId::generate(), $deal->id()));
        $this->connection->executeStatement('UPDATE crm_tasks SET done_at = now() WHERE deal_id = ?', [$deal->id()]);

        self::assertFalse($this->dispatch(new CreateRenewalTask(EntityId::generate(), $deal->id())));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', $deal->id()));
    }

    public function test_a_deal_out_of_scope_is_skipped(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $owner = $this->staff();
        $renewed = $this->ending('2026-12-31', ['owner' => $owner]);
        DealFactory::createOne(['renewedFrom' => $renewed, 'company' => $renewed->company()]);

        foreach ([
            'open' => DealFactory::new()->inStage('offer')->create(['owner' => $owner, 'contractEndOn' => new \DateTimeImmutable('2026-12-31')]),
            'no end' => DealFactory::new()->inStage('won')->create(['owner' => $owner]),
            'ended' => $this->ending('2026-10-31', ['owner' => $owner]),
            'too far' => $this->ending('2027-01-01', ['owner' => $owner]),
            'renewed' => $renewed,
        ] as $case => $deal) {
            self::assertFalse($this->dispatch(new CreateRenewalTask(EntityId::generate(), $deal->id())), $case);
            self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', $deal->id()), $case);
        }
    }

    public function test_the_database_holds_the_one_task_per_deal(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31', ['owner' => $this->staff()]);
        $this->dispatch(new CreateRenewalTask(EntityId::generate(), $deal->id()));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->executeStatement(
            "INSERT INTO crm_tasks (id, title, due_on, assignee_id, deal_id, is_renewal) SELECT ?, 'Doppelt', due_on, assignee_id, deal_id, TRUE FROM crm_tasks WHERE deal_id = ?",
            [EntityId::generate(), $deal->id()],
        );
    }

    /**
     * @return iterable<string, array{string, ?LostReason}>
     */
    public static function stagesAfterWon(): iterable
    {
        yield 'back in the pipeline' => ['negotiation', null];
        yield 'lost' => ['lost', LostReason::Budget];
    }

    #[DataProvider('stagesAfterWon')]
    public function test_leaving_won_completes_the_open_task(string $stage, ?LostReason $reason): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31');
        $task = $this->renewalTask($deal);

        $this->dispatch(new MoveDealStage($deal->id(), $stage, 'won', $this->staff()->id(), $reason));

        self::assertSame(['due_on' => '2026-12-01', 'done_at' => '2026-11-01 10:00', 'updated_at' => '2026-11-01 10:00'], $this->state($task));
    }

    public function test_leaving_won_without_a_task_does_nothing(): void
    {
        $deal = $this->ending('2026-12-31');

        $this->dispatch(new MoveDealStage($deal->id(), 'offer', 'won', $this->staff()->id()));

        self::assertSame('offer', $this->connection->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', $deal->id()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function newEnds(): iterable
    {
        yield 'later' => ['2027-03-31', '2027-03-01'];
        yield 'its lead has passed' => ['2026-11-20', '2026-11-01'];
        yield 'already over' => ['2026-10-01', '2026-11-01'];
    }

    #[DataProvider('newEnds')]
    public function test_a_new_contract_end_moves_the_open_task(string $end, string $due): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31');
        $task = $this->renewalTask($deal);

        self::assertSame($end, $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, $end, '2026-12-31')));

        self::assertSame(['due_on' => $due, 'done_at' => null, 'updated_at' => '2026-11-01 10:00'], $this->state($task));
    }

    public function test_an_emptied_contract_end_completes_the_open_task(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31');
        $task = $this->renewalTask($deal);

        $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, null, '2026-12-31'));

        self::assertSame(['due_on' => '2026-12-01', 'done_at' => '2026-11-01 10:00', 'updated_at' => '2026-11-01 10:00'], $this->state($task));
    }

    /**
     * @return iterable<string, array{\Closure(Deal, string): object}>
     */
    public static function changesATaskFollows(): iterable
    {
        yield 'leaves won' => [static fn (Deal $deal, string $actor): object => new MoveDealStage($deal->id(), 'offer', 'won', $actor)];
        yield 'new end' => [static fn (Deal $deal, string $actor): object => new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, '2027-03-31', '2026-12-31')];
        yield 'no end' => [static fn (Deal $deal, string $actor): object => new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, null, '2026-12-31')];
    }

    /**
     * @param \Closure(Deal, string): object $change
     */
    #[DataProvider('changesATaskFollows')]
    public function test_a_done_task_stays_as_it_is(\Closure $change): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31');
        $task = TaskFactory::new()->done(new \DateTimeImmutable('2026-10-01 09:00:00 UTC'))->create(['deal' => $deal, 'isRenewal' => true, 'dueOn' => new \DateTimeImmutable('2026-12-01')]);

        $this->dispatch($change($deal, $this->staff()->id()));

        self::assertSame(['due_on' => '2026-12-01', 'done_at' => '2026-10-01 09:00', 'updated_at' => '2026-10-01 09:00'], $this->state($task));
    }

    /**
     * @param \Closure(Deal, string): object $change
     */
    #[DataProvider('changesATaskFollows')]
    public function test_only_the_renewal_task_follows(\Closure $change): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $deal = $this->ending('2026-12-31');
        $other = TaskFactory::createOne(['deal' => $deal, 'dueOn' => new \DateTimeImmutable('2026-12-01')]);
        $renewal = $this->renewalTask($deal);
        $otherBefore = $this->state($other);
        $renewalBefore = $this->state($renewal);

        $this->dispatch($change($deal, $this->staff()->id()));

        self::assertNotSame($renewalBefore, $this->state($renewal));
        self::assertSame($otherBefore, $this->state($other));
    }

    public function test_the_task_moves_only_with_the_end_of_a_won_deal(): void
    {
        self::mockTime('2026-11-01 10:00:00 UTC');
        $won = $this->ending('2026-12-31');
        $onWon = $this->renewalTask($won);
        $open = DealFactory::new()->inStage('offer')->create(['contractEndOn' => new \DateTimeImmutable('2026-12-31')]);
        $onOpen = $this->renewalTask($open);
        $before = [$this->state($onWon), $this->state($onOpen)];

        $this->dispatch(new ChangeDealProperty($won->id(), DealProperty::ContractStart, '2026-01-01', null));
        $this->dispatch(new ChangeDealProperty($open->id(), DealProperty::ContractEnd, '2027-03-31', '2026-12-31'));

        self::assertSame($before, [$this->state($onWon), $this->state($onOpen)]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function ending(string $on, array $attributes = []): Deal
    {
        return DealFactory::new()->inStage('won')->create(['contractEndOn' => new \DateTimeImmutable($on)] + $attributes);
    }

    private function renewalTask(Deal $deal): Task
    {
        return TaskFactory::createOne(['deal' => $deal, 'isRenewal' => true, 'dueOn' => new \DateTimeImmutable('2026-12-01')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Task $task): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT due_on, to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS done_at, to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS updated_at FROM crm_tasks WHERE id = ?",
            [$task->id()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function staff(AdminRole $role = AdminRole::Editor, bool $active = true): AdminUser
    {
        $user = AdminUserFactory::createOne(['role' => $role]);
        if (!$active) {
            $this->connection->executeStatement('UPDATE admin_users SET is_active = false WHERE id = ?', [$user->id()]);
            $this->em->refresh($user);
        }

        return $user;
    }

    private function assigneeOf(Deal $deal): string
    {
        $id = EntityId::generate();
        $this->dispatch(new CreateRenewalTask($id, $deal->id()));

        return (string) $this->connection->fetchOne('SELECT assignee_id FROM crm_tasks WHERE id = ?', [$id]);
    }
}
