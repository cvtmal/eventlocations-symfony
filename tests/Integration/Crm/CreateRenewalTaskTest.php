<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\CreateRenewalTask;
use App\Crm\Entity\Deal;
use App\Crm\Exception\NoRenewalAssignee;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * D24: one task per deal, for good. The assignee chain, the due date's clamp, and what the scope re-check skips.
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

        $this->failure(new CreateRenewalTask(EntityId::generate(), $deal->id()), NoRenewalAssignee::class);

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
     * @param array<string, mixed> $attributes
     */
    private function ending(string $on, array $attributes = []): Deal
    {
        return DealFactory::new()->inStage('won')->create(['contractEndOn' => new \DateTimeImmutable($on)] + $attributes);
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
