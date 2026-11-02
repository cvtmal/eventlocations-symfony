<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\CreateInvoiceTask;
use App\Crm\Exception\NoTaskAssignee;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * POR-14, Q20: the owner's reminder on the location's company, due 30 days on by Zurich's calendar, assigned as a
 * renewal task is.
 */
final class CreateInvoiceTaskTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_writes_the_task_on_the_company_for_its_owner(): void
    {
        self::mockTime('2026-11-02 10:00:00 UTC');
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne(['owner' => $owner]);
        $id = EntityId::generate();

        $this->dispatch(new CreateInvoiceTask($id, $company->id()));

        self::assertSame(
            ['title' => 'Rechnung stellen', 'due_on' => '2026-12-02', 'assignee_id' => $owner->id(), 'created_by_id' => null, 'company_id' => $company->id(), 'deal_id' => null, 'is_renewal' => false],
            $this->connection->fetchAssociative('SELECT title, due_on, assignee_id, created_by_id, company_id, deal_id, is_renewal FROM crm_tasks WHERE id = ?', [$id]),
        );
        self::assertSame([['command' => CreateInvoiceTask::class, 'changes' => ['on' => 'company', 'invoice' => true]]], $this->auditRows('task', $id));
    }

    public function test_the_due_date_counts_from_the_day_in_zurich(): void
    {
        self::mockTime('2026-11-30 23:30:00 UTC');
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $id = EntityId::generate();

        $this->dispatch(new CreateInvoiceTask($id, $company->id()));

        self::assertSame('2026-12-31', $this->connection->fetchOne('SELECT due_on FROM crm_tasks WHERE id = ?', [$id]));
    }

    public function test_a_deactivated_owner_passes_it_to_the_oldest_active_admin(): void
    {
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne(['owner' => $owner]);
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $this->em->refresh($owner);
        $admin = AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $id = EntityId::generate();

        $this->dispatch(new CreateInvoiceTask($id, $company->id()));

        self::assertSame($admin->id(), $this->connection->fetchOne('SELECT assignee_id FROM crm_tasks WHERE id = ?', [$id]));
    }

    public function test_with_nobody_active_it_fails_and_writes_nothing(): void
    {
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $company = CompanyFactory::createOne();

        $this->failure(new CreateInvoiceTask(EntityId::generate(), $company->id()), NoTaskAssignee::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks'));
    }
}
