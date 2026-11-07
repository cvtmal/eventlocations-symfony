<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\CreateProRequestTask;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\NoTaskAssignee;
use App\Crm\ProRequestTitle;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * F12: a manager's "Pro anfragen" as a reminder on the venue's company, due two days on by Zurich's calendar, assigned
 * as the renewal task is, and written once while it is open.
 */
final class CreateProRequestTaskTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    private const string TITLE = 'Pro-Anfrage: Schloss Seeblick (anna@example.ch)';

    public function test_it_writes_the_task_on_the_company_for_its_owner(): void
    {
        self::mockTime('2026-11-02 10:00:00 UTC');
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne(['owner' => $owner]);
        $id = EntityId::generate();

        self::assertTrue($this->dispatch($this->request($id, $company->id())));

        self::assertSame(
            ['title' => self::TITLE, 'due_on' => '2026-11-04', 'assignee_id' => $owner->id(), 'created_by_id' => null, 'company_id' => $company->id(), 'deal_id' => null, 'is_renewal' => false],
            $this->connection->fetchAssociative('SELECT title, due_on, assignee_id, created_by_id, company_id, deal_id, is_renewal FROM crm_tasks WHERE id = ?', [$id]),
        );
        self::assertSame([['command' => CreateProRequestTask::class, 'changes' => ['on' => 'company', 'pro_request' => true]]], $this->auditRows('task', $id));
    }

    public function test_the_audit_row_holds_neither_the_venue_nor_the_address(): void
    {
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $id = EntityId::generate();

        $this->dispatch($this->request($id, $company->id()));

        $summary = (string) $this->connection->fetchOne('SELECT changes::text FROM admin_audit_log WHERE subject_id = ?', [$id]);
        self::assertStringNotContainsString('Seeblick', $summary);
        self::assertStringNotContainsString('anna', $summary);
    }

    public function test_the_due_date_counts_from_the_day_in_zurich(): void
    {
        self::mockTime('2026-11-30 23:30:00 UTC');
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $id = EntityId::generate();

        $this->dispatch($this->request($id, $company->id()));

        self::assertSame('2026-12-03', $this->connection->fetchOne('SELECT due_on FROM crm_tasks WHERE id = ?', [$id]));
    }

    public function test_a_deactivated_owner_passes_it_to_the_oldest_active_admin(): void
    {
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne(['owner' => $owner]);
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $this->em->refresh($owner);
        $admin = AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $id = EntityId::generate();

        $this->dispatch($this->request($id, $company->id()));

        self::assertSame($admin->id(), $this->connection->fetchOne('SELECT assignee_id FROM crm_tasks WHERE id = ?', [$id]));
    }

    public function test_with_nobody_active_it_fails_and_writes_nothing(): void
    {
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $company = CompanyFactory::createOne();

        $this->failure($this->request(EntityId::generate(), $company->id()), NoTaskAssignee::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks'));
    }

    public function test_an_unknown_company_fails_and_writes_nothing(): void
    {
        $this->failure($this->request(EntityId::generate(), EntityId::generate()), CompanyNotFound::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks'));
    }

    public function test_a_second_request_while_the_first_is_open_writes_nothing(): void
    {
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $first = EntityId::generate();
        $second = EntityId::generate();

        self::assertTrue($this->dispatch($this->request($first, $company->id())));
        self::assertFalse($this->dispatch($this->request($second, $company->id())));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_tasks WHERE company_id = ?', $company->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE id = ?', $second));
    }

    public function test_the_check_comes_before_the_assignee(): void
    {
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $this->dispatch($this->request(EntityId::generate(), $company->id()));
        $this->connection->executeStatement('UPDATE admin_users SET is_active = false');
        $this->em->clear();

        self::assertFalse($this->dispatch($this->request(EntityId::generate(), $company->id())));
    }

    public function test_a_done_task_does_not_hold_back_the_next_request(): void
    {
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $first = EntityId::generate();
        $this->dispatch($this->request($first, $company->id()));
        $this->connection->executeStatement('UPDATE crm_tasks SET done_at = CURRENT_TIMESTAMP WHERE id = ?', [$first]);
        $this->em->clear();

        self::assertTrue($this->dispatch($this->request(EntityId::generate(), $company->id())));

        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_tasks WHERE company_id = ?', $company->id()));
    }

    public function test_another_address_or_another_company_is_its_own_request(): void
    {
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne(['owner' => $owner]);
        $other = CompanyFactory::createOne(['owner' => $owner]);
        $this->dispatch($this->request(EntityId::generate(), $company->id()));

        self::assertTrue($this->dispatch(new CreateProRequestTask(EntityId::generate(), $company->id(), 'Schloss Seeblick', 'petra@example.ch')));
        self::assertTrue($this->dispatch($this->request(EntityId::generate(), $other->id())));

        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_tasks WHERE company_id = ?', $company->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_tasks WHERE company_id = ?', $other->id()));
    }

    public function test_an_open_task_of_staff_with_the_same_words_counts_too(): void
    {
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        TaskFactory::createOne(['company' => $company, 'title' => self::TITLE]);

        self::assertFalse($this->dispatch($this->request(EntityId::generate(), $company->id())));
    }

    public function test_a_title_that_would_not_fit_the_column_is_cut_to_fit(): void
    {
        $company = CompanyFactory::createOne(['owner' => AdminUserFactory::createOne()]);
        $id = EntityId::generate();

        $this->dispatch(new CreateProRequestTask($id, $company->id(), str_repeat('Gasthof zum Löwen ', 8), 'anna.meier.mit.langem.namen@restaurant-seeblick.example.ch'));

        $title = (string) $this->connection->fetchOne('SELECT title FROM crm_tasks WHERE id = ?', [$id]);
        self::assertSame(ProRequestTitle::MAX, mb_strlen($title));
        self::assertStringEndsWith('… (anna.meier.mit.langem.namen@restaurant-seeblick.example.ch)', $title);
    }

    private function request(string $id, string $companyId): CreateProRequestTask
    {
        return new CreateProRequestTask($id, $companyId, 'Schloss Seeblick', 'anna@example.ch');
    }
}
