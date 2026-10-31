<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\Security\AdminIdentity;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\TaskBadge;
use App\Factory\AdminUserFactory;
use App\Factory\TaskFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * The sidebar's count (D20): which tasks it holds, and that it costs a page one statement at most, none when the owners
 * statement carried it.
 */
final class TaskBadgeTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase;

    public function test_it_counts_my_open_tasks_due_today_or_earlier(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $me = AdminUserFactory::createOne();
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-01')]);
        TaskFactory::createOne(['assignee' => AdminUserFactory::createOne(), 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-31 09:00:00 UTC'))->create(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);

        $this->viewAs($me);

        self::assertSame(2, $this->badge()->count());
    }

    public function test_today_is_zurichs(): void
    {
        self::mockTime('2026-10-31 23:30:00 UTC'); // 1 November in Zurich.
        $me = AdminUserFactory::createOne();
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-01')]);

        $this->viewAs($me);

        self::assertSame(1, $this->badge()->count());
    }

    public function test_without_a_user_there_is_no_count_and_no_statement(): void
    {
        $before = $this->statements();

        self::assertNull($this->badge()->count());
        self::assertSame(['NULL', []], $this->badge()->column());
        self::assertSame($before, $this->statements());
    }

    public function test_it_asks_once_per_request(): void
    {
        $this->viewAs(AdminUserFactory::createOne());
        $before = $this->statements();

        $this->badge()->count();
        $this->badge()->count();
        self::assertSame($before + 1, $this->statements());

        $this->badge()->reset();
        $this->badge()->count();
        self::assertSame($before + 2, $this->statements(), 'The next request counts again.');
    }

    public function test_the_owners_statement_brings_the_count_along(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $me = AdminUserFactory::createOne();
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        $this->viewAs($me);
        $before = $this->statements();

        $this->owners()->all();

        self::assertSame(1, $this->badge()->count());
        self::assertSame($before + 1, $this->statements());
    }

    public function test_the_owners_statement_needs_no_user(): void
    {
        AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);

        self::assertContains('Andrea Keller', array_column($this->owners()->all(), 'name'));
        self::assertNull($this->badge()->count());
    }

    private function viewAs(AdminUser $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(AdminIdentity::fromEntity($user, null), 'admin', ['ROLE_EDITOR']));
    }

    private function badge(): TaskBadge
    {
        $badge = self::getContainer()->get(TaskBadge::class);
        self::assertInstanceOf(TaskBadge::class, $badge);

        return $badge;
    }

    private function owners(): OwnerOptionsQuery
    {
        $owners = self::getContainer()->get(OwnerOptionsQuery::class);
        self::assertInstanceOf(OwnerOptionsQuery::class, $owners);

        return $owners;
    }

    private function statements(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return \count($holder->getData()['default'] ?? []);
    }
}
