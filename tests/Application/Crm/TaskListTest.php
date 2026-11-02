<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\CompleteTask;
use App\Crm\Command\CreateTask;
use App\Crm\Command\ReopenTask;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\TaskNotFound;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The task list (CRM-5, D20): the four tabs, its pills, "Aufgabe erfassen", and done and undone by PRG.
 */
final class TaskListTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use CountsQueries;
    use Factories;
    use FailsCommands;
    use ForgedPosts;

    private const string LIST = '/admin/crm/aufgaben';
    private const string SAME_SITE = 'same-origin';

    public function test_the_page_opens_on_my_open_tasks(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        TaskFactory::createOne(['title' => 'Meine', 'assignee' => $me]);
        TaskFactory::createOne(['title' => 'Fremde', 'assignee' => AdminUserFactory::createOne()]);

        $crawler = $this->client->request('GET', self::LIST);

        self::assertResponseIsSuccessful();
        self::assertSame(['Meine'], $this->titles($crawler));
        self::assertSame(['CRM', 'Aufgaben'], $crawler->filter('.breadcrumbs__item')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame(['Meine', 'Heute', 'Überfällig', 'Alle'], $crawler->filter('.tabs .tab')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame('Meine', $crawler->filter('.tab[aria-current="page"]')->text());
        self::assertSame('Andrea Keller', $crawler->filter('#task-list tbody tr td')->eq(2)->text());
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function tabs(): iterable
    {
        yield 'Heute, for everyone' => ['heute', 'Heute', ['Fremde heutige']];
        yield 'Überfällig, for everyone' => ['ueberfaellig', 'Überfällig', ['Fremde alte']];
        yield 'Alle, the done ones last' => ['alle', 'Alle', ['Fremde alte', 'Fremde heutige', 'Erledigte']];
        yield 'an unknown tab is the first' => ['morgen', 'Meine', []];
    }

    /**
     * @param list<string> $titles
     */
    #[DataProvider('tabs')]
    public function test_a_tab_narrows_the_list(string $view, string $active, array $titles): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $other = AdminUserFactory::createOne();
        TaskFactory::createOne(['title' => 'Fremde heutige', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Fremde alte', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Erledigte', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-01')]);

        $crawler = $this->client->request('GET', self::LIST.'?view='.$view);

        self::assertResponseIsSuccessful();
        self::assertSame($titles, $this->titles($crawler));
        self::assertSame($active, $crawler->filter('.tab[aria-current="page"]')->text());
    }

    public function test_an_array_as_tab_is_the_first_tab(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::LIST.'?view[]=alle');

        self::assertResponseIsSuccessful();
        self::assertSame('Meine', $crawler->filter('.tab[aria-current="page"]')->text());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pills(): iterable
    {
        yield 'yesterday' => ['2026-10-30', 'Überfällig', 'pill--amber'];
        yield 'today' => ['2026-10-31', 'Heute', 'pill--blue'];
        yield 'Monday' => ['2026-11-02', 'Mo, 2.11.', 'pill--grey'];
        yield 'next year' => ['2027-01-04', 'Mo, 4.1.2027', 'pill--grey'];
    }

    #[DataProvider('pills')]
    public function test_the_pill_says_where_the_date_stands(string $dueOn, string $label, string $tone): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['assignee' => $me, 'dueOn' => new \DateTimeImmutable($dueOn)]);

        $pill = $this->client->request('GET', self::LIST)->filter('#task-list tbody .pill');

        self::assertSame($label, $pill->text());
        self::assertStringContainsString($tone, (string) $pill->attr('class'));
    }

    public function test_a_done_task_shows_its_date_and_can_be_reopened(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Offerte', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-28')]);

        $crawler = $this->client->request('GET', self::LIST.'?view=alle');

        self::assertSame('Mi, 28.10.', $crawler->filter('#task-list tbody .pill')->text());
        self::assertCount(1, $crawler->filter('.crm-task--done'));
        self::assertSame('«Offerte» wieder öffnen', $crawler->filter('.crm-task__toggle')->attr('aria-label'));
        self::assertSame('0', $crawler->filter('input[name="done"]')->attr('value'));
    }

    public function test_a_row_links_its_company_or_contact(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier']);
        TaskFactory::createOne(['title' => 'A', 'assignee' => $me, 'company' => $company]);
        TaskFactory::createOne(['title' => 'B', 'assignee' => $me, 'contact' => $contact]);
        $deal = DealFactory::createOne(['company' => CompanyFactory::createOne(['name' => 'Seehotel Bellerive'])]);
        TaskFactory::createOne(['title' => 'C', 'assignee' => $me, 'deal' => $deal]);

        $details = $this->client->request('GET', self::LIST)->filter('#task-list .crm-list__detail');

        self::assertSame('Firma · Seeblick Events GmbH', $details->eq(0)->text());
        self::assertSame('/admin/crm/firmen/'.$company->id(), $details->eq(0)->filter('a')->attr('href'));
        self::assertSame('Kontakt · Laura Meier', $details->eq(1)->text());
        self::assertSame('/admin/crm/kontakte/'.$contact->id(), $details->eq(1)->filter('a')->attr('href'));
        self::assertSame('Deal · Seehotel Bellerive', $details->eq(2)->text());
        self::assertSame('/admin/crm/deals/'.$deal->id(), $details->eq(2)->filter('a')->attr('href'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyTabs(): iterable
    {
        yield 'Meine' => ['meine', 'Keine offenen Aufgaben'];
        yield 'Heute' => ['heute', 'Heute ist nichts fällig'];
        yield 'Überfällig' => ['ueberfaellig', 'Nichts ist überfällig'];
        yield 'Alle' => ['alle', 'Noch keine Aufgaben'];
    }

    #[DataProvider('emptyTabs')]
    public function test_an_empty_tab_says_so(string $view, string $title): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::LIST.'?view='.$view);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($title, $crawler->filter('.empty')->text());
    }

    public function test_the_form_starts_due_today_and_assigned_to_the_viewer(): void
    {
        self::mockTime('2026-10-31 23:30:00 UTC'); // 1 November in Zurich.
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        AdminUserFactory::createOne(['displayName' => 'Marc Widmer']);

        $crawler = $this->client->request('GET', self::LIST);

        self::assertSame('2026-11-01', $crawler->filter('#task_dueOn')->attr('value'));
        self::assertSame('date', $crawler->filter('#task_dueOn')->attr('type'));
        self::assertSame($me->id(), $crawler->filter('#task_assignee option[selected]')->attr('value'));
    }

    public function test_a_valid_form_creates_a_task_on_no_record_and_stays_on_the_tab(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $other = AdminUserFactory::createOne();

        $this->post(self::LIST.'?view=alle', ['task' => ['title' => ' Ablage ordnen ', 'dueOn' => '2026-11-03', 'assignee' => $other->id(), '_token' => 'csrf-token']]);

        self::assertResponseRedirects(self::LIST.'?view=alle', 303);
        $row = $this->connection->fetchAssociative('SELECT title, due_on, assignee_id, created_by_id, company_id, contact_id FROM crm_tasks');
        self::assertSame(['title' => 'Ablage ordnen', 'due_on' => '2026-11-03', 'assignee_id' => $other->id(), 'created_by_id' => $me->id(), 'company_id' => null, 'contact_id' => null], $row);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Aufgabe erstellt.', $crawler->filter('.toast')->text());
        self::assertSame(['Ablage ordnen'], $this->titles($crawler));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function invalidForms(): iterable
    {
        yield 'no title' => [['title' => '   '], '#task_title', 'Bitte einen Wert eingeben.'];
        yield 'a title too long' => [['title' => str_repeat('x', 201)], '#task_title', 'höchstens 200 Zeichen'];
        yield 'no date' => [['dueOn' => ''], '#task_dueOn', 'gültiges Datum'];
        yield 'no real date' => [['dueOn' => '2026-02-31'], '#task_dueOn', 'gültiges Datum'];
        yield 'a date out of range' => [['dueOn' => '3026-01-01'], '#task_dueOn', 'gültiges Datum'];
        yield 'an unknown assignee' => [['assignee' => '019a0000-0000-7000-8000-000000000000'], '#task_assignee', ''];
    }

    /**
     * @param array<string, string> $change
     */
    #[DataProvider('invalidForms')]
    public function test_an_invalid_form_is_a_422_with_the_list_and_the_input(array $change, string $field, string $message): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['title' => 'Bleibt', 'assignee' => $me]);

        $crawler = $this->post(self::LIST, ['task' => [...['title' => 'Ablage', 'dueOn' => '2026-11-03', 'assignee' => $me->id(), '_token' => 'csrf-token'], ...$change]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Bleibt'], $this->titles($crawler));
        self::assertCount(1, $crawler->filter($field));
        self::assertStringContainsString($message, $crawler->filter('.form')->text());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
    }

    public function test_an_account_deactivated_after_the_form_opened_lands_on_the_field(): void
    {
        $this->commandsFailWith(InvalidPropertyValue::unknown('owner'));
        ['user' => $me] = $this->logInAs(AdminRole::Editor);

        $crawler = $this->post(self::LIST, ['task' => ['title' => 'Ablage', 'dueOn' => '2026-11-03', 'assignee' => $me->id(), '_token' => 'csrf-token']]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Bitte einen Eintrag aus der Liste wählen.', $crawler->filter('.form')->text());
    }

    public function test_the_form_needs_its_token(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);

        $this->post(self::LIST, ['task' => ['title' => 'Ablage', 'dueOn' => '2026-11-03', 'assignee' => $me->id(), '_token' => 'wrong']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
    }

    public function test_done_marks_it_and_goes_back(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::createOne(['assignee' => $me]);

        $this->toggle($task->id(), ['done' => '1', 'return_to' => '/admin/crm/aufgaben?view=heute&page=2']);

        self::assertResponseRedirects('/admin/crm/aufgaben?view=heute&page=2', 303);
        self::assertNotNull($this->connection->fetchOne('SELECT done_at FROM crm_tasks'));
        self::assertSame(1, $this->auditRows($task->id(), CompleteTask::class));
    }

    public function test_undone_clears_it(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['assignee' => $me]);

        $this->toggle($task->id(), ['done' => '0']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->connection->fetchOne('SELECT done_at FROM crm_tasks'));
        self::assertSame(1, $this->auditRows($task->id(), ReopenTask::class));
    }

    public function test_a_task_already_there_sends_no_command(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $open = TaskFactory::createOne(['assignee' => $me]);
        $done = TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['assignee' => $me]);

        $this->toggle($open->id(), ['done' => '0']);
        self::assertResponseRedirects(self::LIST, 303);
        $this->toggle($done->id(), ['done' => '1']);
        self::assertResponseRedirects(self::LIST, 303);

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'task'"), 'P1: no audit row for a no-op.');
    }

    public function test_a_foreign_return_path_goes_to_the_list(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::createOne(['assignee' => $me]);

        $this->toggle($task->id(), ['done' => '1', 'return_to' => 'https://evil.example/']);

        self::assertResponseRedirects(self::LIST, 303);
    }

    public function test_a_gone_task_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->toggle(EntityId::generate(), ['done' => '1']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Diese Aufgabe gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_task_gone_after_the_check_says_so_too(): void
    {
        $this->commandsFailWith(TaskNotFound::forId('x'));
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::createOne(['assignee' => $me]);

        $this->toggle($task->id(), ['done' => '1']);

        self::assertStringContainsString('Diese Aufgabe gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_toggle_changes_nothing(array $fields, array $server): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::createOne(['assignee' => $me]);

        $this->client->request('POST', self::LIST.'/'.$task->id().'/status', [...$fields, 'done' => '1'], server: $server);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->connection->fetchOne('SELECT done_at FROM crm_tasks'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unclearStates(): iterable
    {
        yield 'missing' => [null];
        yield 'another value' => ['2'];
        yield 'an array' => [['1']];
    }

    #[DataProvider('unclearStates')]
    public function test_an_unclear_state_changes_nothing(mixed $done): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::createOne(['assignee' => $me]);

        $this->client->request('POST', self::LIST.'/'.$task->id().'/status', ['_token' => 'csrf-token', ...(null === $done ? [] : ['done' => $done])], server: ['HTTP_SEC_FETCH_SITE' => self::SAME_SITE]);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->connection->fetchOne('SELECT done_at FROM crm_tasks'));
    }

    public function test_the_list_refuses_anonymous_visitors(): void
    {
        $this->client->request('GET', self::LIST);

        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_audit_row_of_a_task_from_the_form_names_no_record(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);

        $this->post(self::LIST, ['task' => ['title' => 'Ablage', 'dueOn' => '2026-11-03', 'assignee' => $me->id(), '_token' => 'csrf-token']]);

        self::assertSame('{"on": null}', $this->connection->fetchOne('SELECT changes FROM admin_audit_log WHERE command = ?', [CreateTask::class]));
    }

    public function test_the_page_stays_within_its_statements(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createMany(3, ['assignee' => $me, 'company' => CompanyFactory::createOne()]);

        // The staff for the form's select, which carries the sidebar's badge (`TaskBadge`), COUNT, the rows, the
        // access-request badge; with the user's refresh 5 in production (P12 ceiling 6).
        self::assertSame(4, $this->queriesOf(self::LIST));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $url, array $fields): Crawler
    {
        return $this->client->request('POST', $url, $fields, server: ['HTTP_SEC_FETCH_SITE' => self::SAME_SITE]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function toggle(string $id, array $fields): void
    {
        $this->post(self::LIST.'/'.$id.'/status', [...$fields, '_token' => 'csrf-token']);
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('#task-list .crm-task__title')->each(static fn (Crawler $title): string => $title->text());
    }

    private function auditRows(string $taskId, string $command): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'task' AND subject_id = ? AND command = ?", [$taskId, $command]);
    }
}
