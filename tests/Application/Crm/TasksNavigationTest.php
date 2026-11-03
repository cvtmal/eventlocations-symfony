<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\TaskFactory;
use App\Tests\Support\Admin\AdminSession;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Aufgaben" in the sidebar (D20): its badge, and what the badge costs a page. The company list is at its ceiling, so the
 * count rides on the owners statement (`TaskBadge`).
 */
final class TasksNavigationTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use CountsQueries;
    use Factories;

    public function test_the_item_links_to_the_task_list_and_stays_active_there(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/crm/aufgaben');

        $active = $crawler->filter('.sidebar__item[aria-current="page"]');
        self::assertSame('Aufgaben', $active->filter('.sidebar__label')->text());
        self::assertSame('/admin/crm/aufgaben', $active->attr('href'));
        self::assertCount(1, $active->filter('use[href$="#square-check-big"]'));
    }

    public function test_the_badge_counts_my_open_tasks_and_matches_my_tab(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createMany(4, ['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-08')]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-30 10:00:00 UTC'))->create(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['assignee' => AdminUserFactory::createOne(), 'dueOn' => new \DateTimeImmutable('2026-10-30')]);

        $crawler = $this->client->request('GET', '/admin');

        self::assertSame('5', $crawler->filter('.sidebar__badge')->text());

        $crawler = $this->client->request('GET', '/admin/crm/aufgaben?view=meine');

        self::assertSame('5', $crawler->filter('.sidebar__badge')->text());
        self::assertSame('5', $crawler->filter('.page-header h1 .muted')->text());
    }

    public function test_at_nought_there_is_no_badge(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-30 10:00:00 UTC'))->create(['assignee' => $me]);
        TaskFactory::createOne(['assignee' => AdminUserFactory::createOne()]);

        foreach (['/admin', '/admin/crm/firmen'] as $page) {
            $this->client->request('GET', $page);

            self::assertSelectorNotExists('.sidebar__badge');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesWithOwners(): iterable
    {
        yield 'company list' => ['/admin/crm/firmen'];
        yield 'contact list' => ['/admin/crm/kontakte'];
        yield 'task list' => ['/admin/crm/aufgaben'];
        yield 'company record' => ['/admin/crm/firmen/{company}'];
        yield 'contact record' => ['/admin/crm/kontakte/{contact}'];
    }

    #[DataProvider('pagesWithOwners')]
    public function test_a_page_with_owners_shows_the_badge_without_a_statement_of_its_own(string $page): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        $url = strtr($page, ['{company}' => CompanyFactory::createOne()->id(), '{contact}' => ContactFactory::createOne()->id()]);

        $crawler = $this->requestCounting($url);

        self::assertSame('1', $crawler->filter('.sidebar__badge')->text());
        self::assertSame([], array_values(array_filter(
            $this->lastStatements(),
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT COUNT(*) FROM crm_tasks t WHERE t.assignee_id'),
        )));
    }

    public function test_the_company_list_stays_within_its_statements_with_the_users_refresh(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        CompanyFactory::createOne();
        // An empty identity map is production's: the refresh then reads the user, which `CountsQueries` otherwise leaves out.
        $this->entityManager()->clear();

        // The user's refresh, the owners with the badge, COUNT, the rows, the venues left for the bootstrap, the
        // access-request badge (P12 ceiling 5, passed on purpose by #43).
        self::assertSame(6, $this->queriesOf('/admin/crm/firmen'));
        self::assertSelectorTextSame('.sidebar__badge', '1');
    }

    public function test_the_contact_list_holds_its_count_with_the_users_refresh(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        ContactFactory::createOne();
        $this->entityManager()->clear();

        // The user's refresh, the owners with the badge, COUNT, the rows, the access-request badge.
        self::assertSame(5, $this->queriesOf('/admin/crm/kontakte'));
        self::assertSelectorTextSame('.sidebar__badge', '1');
    }

    private function requestCounting(string $url): Crawler
    {
        $this->holder()->reset();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function lastStatements(): array
    {
        return array_values(array_map(static fn (array $query): string => (string) $query['sql'], $this->holder()->getData()['default'] ?? []));
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }
}
