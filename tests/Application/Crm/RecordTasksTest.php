<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\CreateTask;
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
 * Tasks on a record (CRM-5, D20): the composer's endpoint and the Aufgaben card with its pills and toggles.
 */
final class RecordTasksTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    private const string TASKS = '/admin/api/crm/tasks';
    private const string DUE = '2026-11-03';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kinds(): iterable
    {
        yield 'a company' => ['company', 'company_id'];
        yield 'a contact' => ['contact', 'contact_id'];
        yield 'a deal' => ['deal', 'deal_id'];
    }

    #[DataProvider('kinds')]
    public function test_the_composer_creates_a_task_on_the_record(string $kind, string $column): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $assignee = AdminUserFactory::createOne();
        $record = match ($kind) {
            'company' => CompanyFactory::createOne(),
            'contact' => ContactFactory::createOne(),
            default => DealFactory::createOne(),
        };

        $this->send(['kind' => $kind, 'recordId' => $record->id(), 'title' => ' Rückruf ', 'dueOn' => self::DUE, 'assigneeId' => $assignee->id()]);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $id = $this->answer()['id'] ?? null;
        self::assertIsString($id);
        $row = $this->connection->fetchAssociative('SELECT title, due_on, assignee_id, created_by_id, company_id, contact_id, deal_id FROM crm_tasks WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame('Rückruf', $row['title']);
        self::assertSame(self::DUE, $row['due_on']);
        self::assertSame($assignee->id(), $row['assignee_id']);
        self::assertSame($me->id(), $row['created_by_id']);
        self::assertSame($record->id(), $row[$column]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [CreateTask::class]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no title' => [['title' => null], 'title'];
        yield 'a blank title' => [['title' => '   '], 'title'];
        yield 'a title too long' => [['title' => str_repeat('x', 201)], 'title'];
        yield 'a title that is no text' => [['title' => ['x']], 'title'];
        yield 'a NUL in the title' => [['title' => "a\u{0}b"], 'title'];
        yield 'no date' => [['dueOn' => null], 'dueOn'];
        yield 'no real date' => [['dueOn' => '2026-02-31'], 'dueOn'];
        yield 'a date with a time' => [['dueOn' => '2026-11-03T10:00'], 'dueOn'];
        yield 'no assignee' => [['assigneeId' => null], 'assigneeId'];
        yield 'no id' => [['assigneeId' => 'nobody'], 'assigneeId'];
        yield 'an array as assignee' => [['assigneeId' => ['x']], 'assigneeId'];
        yield 'an unknown kind' => [['kind' => 'venue'], 'kind'];
        yield 'no record' => [['recordId' => null], 'recordId'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_input_is_a_422_on_its_field(array $change, string $field): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->send([...['kind' => 'company', 'recordId' => $company->id(), 'title' => 'Rückruf', 'dueOn' => self::DUE, 'assigneeId' => $me->id()], ...$change]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertArrayHasKey($field, $this->errors());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
    }

    public function test_an_assignee_who_cannot_take_a_task_is_a_422_on_that_input(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->send(['kind' => 'company', 'recordId' => $company->id(), 'title' => 'Rückruf', 'dueOn' => self::DUE, 'assigneeId' => EntityId::generate()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['assigneeId'], array_keys($this->errors()));
    }

    public function test_a_missing_record_is_a_404(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);

        $this->send(['kind' => 'contact', 'recordId' => EntityId::generate(), 'title' => 'Rückruf', 'dueOn' => self::DUE, 'assigneeId' => $me->id()]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([''], array_keys($this->errors()));
    }

    public function test_invalid_utf8_is_a_422_not_an_html_error(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->sendRaw("{\"title\": \"\xC3\x28\"}");

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_post_without_the_token_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', self::TASKS, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_tasks'));
    }

    public function test_the_island_is_told_the_route(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $props = json_decode((string) $crawler->filter('[data-island="timeline"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame(self::TASKS, $props['urls']['tasks'] ?? null);
    }

    public function test_the_card_on_a_company_shows_its_open_tasks_with_their_pills(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        TaskFactory::createOne(['title' => 'Überfällige', 'company' => $company, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Rückruf Laura Meier', 'contact' => $contact, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Spätere', 'company' => $company, 'dueOn' => new \DateTimeImmutable('2026-11-02')]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Erledigte', 'company' => $company]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $card = $crawler->filter('#aufgaben');
        self::assertSame('Aufgaben (3)', $card->filter('h2')->text());
        self::assertSame(['Überfällige', 'Rückruf Laura Meier', 'Spätere'], $card->filter('.crm-task__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['Überfällig', 'Heute', 'Mo, 2.11.'], $card->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text()));
        self::assertSame('«Überfällige» als erledigt markieren', $card->filter('.crm-task__toggle')->first()->attr('aria-label'));
        self::assertSame('/admin/crm/locations/'.$company->id().'#aufgaben', $card->filter('input[name="return_to"]')->first()->attr('value'));
    }

    public function test_the_card_on_a_company_shows_a_renewal_task_on_its_deal(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        TaskFactory::createOne(['title' => 'Verlängerung: Premium 2026', 'deal' => DealFactory::createOne(['company' => $company]), 'isRenewal' => true]);

        $card = $this->client->request('GET', '/admin/crm/locations/'.$company->id())->filter('#aufgaben');

        self::assertSame('Aufgaben (1)', $card->filter('h2')->text());
        self::assertSame('Verlängerung: Premium 2026', $card->filter('.crm-task__title')->text());
    }

    public function test_the_card_on_a_contact_shows_its_tasks(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne()]);
        $task = TaskFactory::createOne(['title' => 'Rückruf', 'contact' => $contact]);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertSame('Aufgaben (1)', $crawler->filter('#aufgaben h2')->text());
        self::assertSame('Rückruf', $crawler->filter('#aufgaben .crm-task__title')->text());
        self::assertSame('/admin/crm/aufgaben/'.$task->id().'/status', $crawler->filter('#aufgaben form')->attr('action'));
    }

    public function test_the_card_on_a_deal_shows_its_tasks_and_only_those(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();
        TaskFactory::createOne(['deal' => $deal, 'title' => 'Offerte senden']);
        TaskFactory::createOne(['company' => $deal->company(), 'title' => 'Fotos anfragen']);

        $crawler = $this->client->request('GET', '/admin/crm/deals/'.$deal->id());

        self::assertSame(['Offerte senden'], $crawler->filter('#aufgaben .crm-task__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame('/admin/crm/deals/'.$deal->id().'#aufgaben', $crawler->filter('#aufgaben input[name="return_to"]')->attr('value'));
    }

    public function test_a_card_without_tasks_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertSame('Aufgaben (0)', $crawler->filter('#aufgaben h2')->text());
        self::assertSame('Keine offenen Aufgaben.', $crawler->filter('#aufgaben .crm-side__empty')->text());
    }

    public function test_done_on_the_card_comes_back_to_the_card(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        TaskFactory::createOne(['title' => 'Rückruf', 'company' => $company]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());
        $form = $crawler->filter('#aufgaben form')->form();
        $this->client->submit($form, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#aufgaben', 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Aufgaben (0)', $crawler->filter('#aufgaben h2')->text());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(array $body): void
    {
        $this->sendRaw(json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function sendRaw(string $body): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $this->client->request('POST', self::TASKS, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        /** @var array<string, mixed> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer;
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(): array
    {
        $errors = $this->answer()['errors'] ?? null;
        self::assertIsArray($errors);

        /* @var array<string, list<string>> $errors */
        return $errors;
    }
}
