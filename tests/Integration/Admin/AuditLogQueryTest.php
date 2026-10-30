<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\ReadModel\AuditLogFilter;
use App\Admin\ReadModel\AuditLogQuery;
use App\Admin\ValueObject\AuditActorType;
use App\Factory\AdminUserFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class AuditLogQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_rows_come_newest_first_with_the_actor_name(): void
    {
        $user = AdminUserFactory::createOne(['displayName' => 'Anna Admin']);
        $this->entry('2026-10-30 09:00:00+00', actorId: $user->id());
        $this->entry('2026-10-30 10:00:00+00');

        $page = $this->query()->search(new AuditLogFilter(), 1);

        self::assertSame(2, $page->total);
        self::assertCount(2, $page->rows);
        self::assertSame(AuditActorType::Console, $page->rows[0]->actorType);
        self::assertNull($page->rows[0]->actorName);
        self::assertSame('Anna Admin', $page->rows[1]->actorName);
        self::assertSame(AuditActorType::AdminUser, $page->rows[1]->actorType);
        self::assertSame('CreateAdminUser', $page->rows[0]->commandName());
        self::assertSame('11:00', $page->rows[0]->timeInZurich());
        self::assertSame(['role' => 'editor'], $page->rows[0]->changes);
    }

    public function test_it_filters_by_actor(): void
    {
        $user = AdminUserFactory::createOne();
        $this->entry('2026-10-30 09:00:00+00', actorId: $user->id());
        $this->entry('2026-10-30 10:00:00+00');

        self::assertSame(1, $this->query()->search(new AuditLogFilter(actorId: $user->id()), 1)->total);
    }

    public function test_it_filters_by_subject(): void
    {
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: 'a');
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: 'b');
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'deal', subjectId: 'a');

        self::assertSame(2, $this->query()->search(new AuditLogFilter(subjectType: 'venue'), 1)->total);
        self::assertSame(2, $this->query()->search(new AuditLogFilter(subjectId: 'a'), 1)->total);
        self::assertSame(1, $this->query()->search(new AuditLogFilter(subjectType: 'venue', subjectId: 'b'), 1)->total);
    }

    public function test_dates_are_zurich_days_both_inclusive(): void
    {
        $this->entry('2026-10-29 22:59:00+00');
        $this->entry('2026-10-29 23:01:00+00');
        $this->entry('2026-10-30 22:59:00+00');
        $this->entry('2026-10-30 23:01:00+00');
        $day = new \DateTimeImmutable('2026-10-30');

        self::assertSame(2, $this->query()->search(new AuditLogFilter(from: $day, to: $day), 1)->total);
        self::assertSame(3, $this->query()->search(new AuditLogFilter(from: $day), 1)->total);
        self::assertSame(3, $this->query()->search(new AuditLogFilter(to: $day), 1)->total);
    }

    public function test_it_pages_by_fifty(): void
    {
        for ($minute = 0; $minute < 51; ++$minute) {
            $this->entry(\sprintf('2026-10-30 09:%02d:00+00', $minute % 60));
        }

        $first = $this->query()->search(new AuditLogFilter(), 1);
        $second = $this->query()->search(new AuditLogFilter(), 2);

        self::assertCount(50, $first->rows);
        self::assertCount(1, $second->rows);
        self::assertSame(51, $first->total);
        self::assertSame(2, $first->lastPage());
        self::assertSame(2, $second->page);
    }

    public function test_a_page_past_the_end_lands_on_the_last_page(): void
    {
        for ($minute = 0; $minute < 51; ++$minute) {
            $this->entry(\sprintf('2026-10-30 09:%02d:00+00', $minute % 60));
        }

        $huge = $this->query()->search(new AuditLogFilter(), \PHP_INT_MAX);

        self::assertSame(2, $huge->page);
        self::assertCount(1, $huge->rows);
    }

    public function test_an_empty_log_stays_on_page_one(): void
    {
        $empty = $this->query()->search(new AuditLogFilter(), \PHP_INT_MAX);

        self::assertSame(1, $empty->page);
        self::assertSame([], $empty->rows);
    }

    public function test_for_subject_returns_the_latest_rows_of_one_record(): void
    {
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: 'a');
        $this->entry('2026-10-30 10:00:00+00', subjectType: 'venue', subjectId: 'a');
        $this->entry('2026-10-30 11:00:00+00', subjectType: 'venue', subjectId: 'b');

        $rows = $this->query()->forSubject('venue', 'a', 1);

        self::assertCount(1, $rows);
        self::assertSame('11:00', $rows[0]->timeInZurich());
    }

    private function query(): AuditLogQuery
    {
        return new AuditLogQuery($this->connection);
    }

    private function entry(string $at, ?string $actorId = null, string $subjectType = 'admin_user', string $subjectId = 'x'): void
    {
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => null === $actorId ? 'console' : 'admin_user',
            'actor_id' => $actorId,
            'command' => 'App\Admin\Command\CreateAdminUser',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => '{"role": "editor"}',
            'created_at' => $at,
        ]);
    }
}
