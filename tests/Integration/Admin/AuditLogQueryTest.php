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

    public function test_it_filters_by_actor_type_and_combines_with_the_subject(): void
    {
        $this->entry('2026-10-30 09:00:00+00', actorId: AdminUserFactory::createOne()->id(), subjectType: 'venue');
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue');
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', actorType: AuditActorType::PortalUser);
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'portal_user', actorType: AuditActorType::PortalUser);

        self::assertSame(2, $this->query()->search(new AuditLogFilter(actorType: AuditActorType::PortalUser), 1)->total);
        self::assertSame(1, $this->query()->search(new AuditLogFilter(actorType: AuditActorType::AdminUser), 1)->total);
        self::assertSame(0, $this->query()->search(new AuditLogFilter(actorType: AuditActorType::System), 1)->total);
        self::assertSame(1, $this->query()->search(new AuditLogFilter(subjectType: 'venue', actorType: AuditActorType::PortalUser), 1)->total);
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

    public function test_for_subjects_merges_ids_and_commands_newest_first(): void
    {
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: '1', command: 'A');
        $this->entry('2026-10-30 10:00:00+00', subjectType: 'venue', subjectId: '2', command: 'A');
        $this->entry('2026-10-30 11:00:00+00', subjectType: 'venue', subjectId: '3', command: 'A');
        $this->entry('2026-10-30 12:00:00+00', subjectType: 'venue', subjectId: '1', command: 'Other');
        $this->entry('2026-10-30 13:00:00+00', subjectType: 'deal', subjectId: '1', command: 'A');

        $rows = $this->query()->forSubjects('venue', ['1', '2'], ['A'], 10);

        // Zurich is an hour ahead of UTC from 25 October.
        self::assertSame(['11:00' => '2', '10:00' => '1'], self::byTime($rows));
    }

    public function test_for_subjects_pages_by_keyset_across_equal_timestamps(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: '1', command: 'A');
        }
        rsort($ids);

        $first = $this->query()->forSubjects('venue', ['1'], ['A'], 2);
        $second = $this->query()->forSubjects('venue', ['1'], ['A'], 2, [$first[1]->createdAt, $first[1]->id]);
        $third = $this->query()->forSubjects('venue', ['1'], ['A'], 2, [$second[1]->createdAt, $second[1]->id]);

        self::assertSame($ids, array_map(static fn ($row): string => $row->id, [...$first, ...$second, ...$third]));
    }

    public function test_for_subjects_cuts_a_command_that_must_name_a_field(): void
    {
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: '1', command: 'Basics', changes: '{"changed": ["name"], "tier": "basic"}');
        $kept = $this->entry('2026-10-30 10:00:00+00', subjectType: 'venue', subjectId: '1', command: 'Basics', changes: '{"changed": ["name", "tier"], "tier": "premium"}');
        $other = $this->entry('2026-10-30 11:00:00+00', subjectType: 'venue', subjectId: '1', command: 'Published', changes: '{"status": "published"}');

        $rows = $this->query()->forSubjects('venue', ['1'], ['Basics', 'Published'], 10, null, ['Basics' => 'tier']);

        self::assertSame([$other, $kept], array_map(static fn ($row): string => $row->id, $rows));
    }

    public function test_for_subjects_without_ids_or_commands_runs_no_statement(): void
    {
        $this->entry('2026-10-30 09:00:00+00', subjectType: 'venue', subjectId: '1', command: 'A');

        self::assertSame([], $this->query()->forSubjects('venue', [], ['A'], 10));
        self::assertSame([], $this->query()->forSubjects('venue', ['1'], [], 10));
    }

    /**
     * @param list<\App\Admin\ReadModel\AuditLogRow> $rows
     *
     * @return array<string, string>
     */
    private static function byTime(array $rows): array
    {
        $byTime = [];
        foreach ($rows as $row) {
            $byTime[$row->timeInZurich()] = $row->subjectId;
        }

        return $byTime;
    }

    private function query(): AuditLogQuery
    {
        return new AuditLogQuery($this->connection);
    }

    private function entry(string $at, ?string $actorId = null, string $subjectType = 'admin_user', string $subjectId = 'x', string $command = 'App\Admin\Command\CreateAdminUser', string $changes = '{"role": "editor"}', ?AuditActorType $actorType = null): string
    {
        $id = EntityId::generate();
        $this->connection->insert('admin_audit_log', [
            'id' => $id,
            'actor_type' => $actorType->value ?? (null === $actorId ? 'console' : 'admin_user'),
            'actor_id' => $actorId,
            'command' => $command,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => $changes,
            'created_at' => $at,
        ]);

        return $id;
    }
}
