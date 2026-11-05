<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\TaskListQuery;
use App\Crm\ReadModel\TaskRow;
use App\Crm\ReadModel\TaskView;
use App\Crm\ValueObject\RecordKind;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tabs of the task list (CRM-5), "today" in Zurich, and what a record's card collects.
 */
final class TaskListQueryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase;

    public function test_each_tab_holds_its_tasks(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $me = AdminUserFactory::createOne();
        $other = AdminUserFactory::createOne();
        TaskFactory::createOne(['title' => 'Meine alte', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Meine heutige', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Meine spätere', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-02')]);
        TaskFactory::createOne(['title' => 'Fremde heutige', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Fremde alte', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-01')]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Erledigte', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-28')]);

        self::assertSame(['Meine alte', 'Meine heutige', 'Meine spätere'], $this->titles(TaskView::Mine, $me->id()));
        self::assertSame(['Fremde heutige', 'Meine heutige'], $this->titles(TaskView::Today, $me->id()));
        self::assertSame(['Fremde alte', 'Meine alte'], $this->titles(TaskView::Overdue, $me->id()));
        self::assertSame(['Fremde alte', 'Meine alte', 'Fremde heutige', 'Meine heutige', 'Meine spätere', 'Erledigte'], $this->titles(TaskView::All, $me->id()), 'Open first, then by date.');
    }

    public function test_today_turns_at_midnight_in_zurich_not_in_utc(): void
    {
        self::mockTime('2026-10-31 23:30:00 UTC'); // 1 November in Zurich.
        $me = AdminUserFactory::createOne();
        TaskFactory::createOne(['title' => 'Samstag', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Sonntag', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-01')]);

        self::assertSame(['Sonntag'], $this->titles(TaskView::Today, $me->id()));
        self::assertSame(['Samstag'], $this->titles(TaskView::Overdue, $me->id()));
    }

    public function test_my_widget_holds_my_next_open_tasks_and_their_total(): void
    {
        $me = AdminUserFactory::createOne();
        TaskFactory::createOne(['title' => 'Spätere', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-03')]);
        TaskFactory::createOne(['title' => 'Nächste', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Letzte', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-09')]);
        TaskFactory::createOne(['title' => 'Fremde', 'assignee' => AdminUserFactory::createOne()]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Erledigte', 'assignee' => $me]);

        $preview = $this->query()->mine($me->id(), 2);

        self::assertSame(['Nächste', 'Spätere'], array_column($preview->rows, 'title'));
        self::assertSame(3, $preview->total);
    }

    public function test_the_overdue_widget_holds_anyones_oldest_tasks_and_their_total(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $me = AdminUserFactory::createOne();
        $other = AdminUserFactory::createOne();
        TaskFactory::createOne(['title' => 'Gestern', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Vorgestern', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-29')]);
        TaskFactory::createOne(['title' => 'Letzte Woche', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-20')]);
        TaskFactory::createOne(['title' => 'Heute', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);

        $preview = $this->query()->overdue(2);

        self::assertSame(['Letzte Woche', 'Vorgestern'], array_column($preview->rows, 'title'));
        self::assertSame(3, $preview->total);
    }

    public function test_an_empty_widget_has_no_rows_and_a_total_of_nought(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');

        $preview = $this->query()->mine(AdminUserFactory::createOne()->id(), 5);

        self::assertSame([], $preview->rows);
        self::assertSame(0, $preview->total);
        self::assertSame(0, $this->query()->overdue(5)->total);
    }

    public function test_a_row_names_its_assignee_and_its_object(): void
    {
        $me = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $deal = DealFactory::createOne(['company' => CompanyFactory::createOne(['name' => 'Alpenhof AG'])]);
        TaskFactory::createOne(['title' => 'A', 'assignee' => $me, 'company' => $company]);
        TaskFactory::createOne(['title' => 'B', 'assignee' => $me, 'deal' => $deal]);
        TaskFactory::createOne(['title' => 'C', 'assignee' => $me]);

        [$a, $b, $c] = $this->rows(TaskView::Mine, $me->id());

        self::assertSame('Andrea Keller', $a->assigneeName);
        self::assertSame([$company->id(), 'Seeblick Events GmbH', null], [$a->companyId, $a->companyName, $a->dealId]);
        self::assertSame([null, $deal->id(), 'Alpenhof AG'], [$b->companyId, $b->dealId, $b->dealCompanyName]);
        self::assertSame([null, null, false], [$c->companyId, $c->dealId, $c->hasObject()]);
    }

    public function test_a_tab_pages_at_fifty(): void
    {
        $me = AdminUserFactory::createOne();
        TaskFactory::createMany(51, ['assignee' => $me]);

        $first = $this->query()->page(TaskView::Mine, $me->id(), 1);
        $second = $this->query()->page(TaskView::Mine, $me->id(), 2);
        $beyond = $this->query()->page(TaskView::Mine, $me->id(), 9);

        self::assertCount(50, $first->rows);
        self::assertSame([51, 2], [$first->total, $first->lastPage]);
        self::assertCount(1, $second->rows);
        self::assertSame(2, $beyond->page);
    }

    public function test_a_company_card_also_collects_the_tasks_on_its_deals(): void
    {
        $company = CompanyFactory::createOne();
        $deal = DealFactory::createOne(['company' => $company]);
        TaskFactory::createOne(['title' => 'Auf dem Deal', 'deal' => $deal]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Erledigt', 'deal' => $deal]);
        TaskFactory::createOne(['title' => 'Fremder Deal', 'deal' => DealFactory::createOne()]);

        self::assertSame(['Auf dem Deal'], array_map(static fn (TaskRow $row): string => $row->title, $this->query()->openOf(RecordKind::Company, $company->id())));
    }

    public function test_a_row_on_a_deal_names_the_deal_and_its_company(): void
    {
        $me = AdminUserFactory::createOne();
        $deal = DealFactory::createOne(['company' => CompanyFactory::createOne(['name' => 'Seehotel Bellerive'])]);
        TaskFactory::createOne(['assignee' => $me, 'deal' => $deal]);

        $row = $this->query()->mine($me->id(), 5)->rows[0];

        self::assertSame($deal->id(), $row->dealId);
        self::assertSame('Seehotel Bellerive', $row->dealCompanyName);
        self::assertNull($row->companyId);
        self::assertTrue($row->hasObject());
    }

    public function test_is_done_tells_open_done_and_gone_apart(): void
    {
        $open = TaskFactory::createOne();
        $done = TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create();

        self::assertFalse($this->query()->isDone($open->id()));
        self::assertTrue($this->query()->isDone($done->id()));
        self::assertNull($this->query()->isDone('019a0000-0000-7000-8000-000000000000'));
    }

    /**
     * @return list<string>
     */
    private function titles(TaskView $view, string $viewerId): array
    {
        return array_map(static fn (TaskRow $row): string => $row->title, $this->rows($view, $viewerId));
    }

    /**
     * @return list<TaskRow>
     */
    private function rows(TaskView $view, string $viewerId): array
    {
        return $this->query()->page($view, $viewerId, 1)->rows;
    }

    private function query(): TaskListQuery
    {
        $query = self::getContainer()->get(TaskListQuery::class);
        self::assertInstanceOf(TaskListQuery::class, $query);

        return $query;
    }
}
