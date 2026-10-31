<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\LogActivity;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\CompanyVenue;
use App\Crm\ReadModel\TimelineQuery;
use App\Factory\ActivityFactory;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Command\PublishVenue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The timeline's JSON endpoints (CRM-4, D12, D14): the composer's four kinds, edit and delete of your own entry,
 * the pages the island loads, every error as JSON.
 */
final class TimelineApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string ACTIVITIES = '/admin/api/crm/activities';

    /**
     * @return iterable<string, array{string}>
     */
    public static function composableTypes(): iterable
    {
        yield 'Notiz' => [ActivityType::NOTE];
        yield 'E-Mail' => [ActivityType::EMAIL_LOGGED];
        yield 'Anruf' => [ActivityType::CALL];
        yield 'Meeting' => [ActivityType::MEETING];
    }

    #[DataProvider('composableTypes')]
    public function test_a_new_entry_answers_201_with_the_entry(string $type): void
    {
        $staff = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne();

        $this->send('POST', self::ACTIVITIES, ['kind' => 'company', 'recordId' => $company->id(), 'type' => $type, 'body' => " Rückruf\nam Montag ", 'occurredAt' => '2026-10-31T14:05']);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $entry = $this->entry();
        self::assertIsString($entry['id']);
        unset($entry['id']);
        self::assertSame([
            'kind' => 'activity',
            'type' => $type,
            'occurredAt' => '2026-10-31T14:05',
            'actorName' => 'Andrea Keller',
            'title' => null,
            'body' => "Rückruf\nam Montag",
            'anonymised' => false,
            'canEdit' => true,
            'venueNumber' => null,
            'venueName' => null,
            'tier' => null,
        ], $entry);
        self::assertSame($staff['user']->id(), $this->connection->fetchOne('SELECT actor_id FROM crm_activities LIMIT 1'));
        self::assertSame('2026-10-31 14:05:00', $this->connection->fetchOne("SELECT to_char(occurred_at AT TIME ZONE 'Europe/Zurich', 'YYYY-MM-DD HH24:MI:SS') FROM crm_activities LIMIT 1"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [LogActivity::class]));
    }

    public function test_an_entry_on_a_contact_shows_on_both_timelines(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);

        $this->send('POST', self::ACTIVITIES, ['kind' => 'contact', 'recordId' => $contact->id(), 'type' => 'note', 'body' => 'Beide', 'occurredAt' => '2026-10-31T14:05']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->entry()['id'];

        self::assertSame([$id], $this->idsOf($this->timelineUrl('contacts', $contact->id())));
        self::assertSame([$id], $this->idsOf($this->timelineUrl('companies', $company->id())));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'no text' => [['body' => null], 'body'];
        yield 'blank text' => [['body' => " \n "], 'body'];
        yield 'text that is not a string' => [['body' => ['x']], 'body'];
        yield 'too long' => [['body' => str_repeat('a', 20001)], 'body'];
        yield 'no date' => [['occurredAt' => null], 'occurredAt'];
        yield 'a date alone' => [['occurredAt' => '2026-10-31'], 'occurredAt'];
        yield 'no such day' => [['occurredAt' => '2026-02-31T10:00'], 'occurredAt'];
        yield 'year zero' => [['occurredAt' => '0000-01-01T00:00'], 'occurredAt'];
        yield 'no type' => [['type' => null], 'type'];
        yield 'an unknown type' => [['type' => 'fax'], 'type'];
        yield 'a type that is not a string' => [['type' => ['note']], 'type'];
        yield 'an unknown kind' => [['kind' => 'deal'], 'kind'];
        yield 'no record' => [['recordId' => null], 'recordId'];
        yield 'a record that is not a string' => [['recordId' => 12], 'recordId'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('invalidEntries')]
    public function test_an_invalid_entry_is_a_422_on_its_field(array $change, string $field): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->send('POST', self::ACTIVITIES, [...['kind' => 'company', 'recordId' => $company->id(), 'type' => 'note', 'body' => 'Text', 'occurredAt' => '2026-10-31T14:05'], ...$change]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([$field], array_keys($this->errors()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_nul_character_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->sendRaw('POST', self::ACTIVITIES, \sprintf('{"kind": "company", "recordId": "%s", "type": "note", "body": "a\u0000b", "occurredAt": "2026-10-31T14:05"}', $company->id()));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['body'], array_keys($this->errors()));
    }

    public function test_invalid_utf8_is_a_json_422_not_an_html_400(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $activity = ActivityFactory::new()->onCompany($company)->create();

        $this->sendRaw('POST', self::ACTIVITIES, \sprintf("{\"kind\": \"company\", \"recordId\": \"%s\", \"type\": \"note\", \"body\": \"a\xFFb\", \"occurredAt\": \"2026-10-31T14:05\"}", $company->id()));
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->sendRaw('PATCH', self::ACTIVITIES.'/'.$activity->id(), "{\"body\": \"a\xFFb\", \"occurredAt\": \"2026-10-31T14:05\"}");
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->client->request('GET', $this->timelineUrl('companies', $company->id()).'?type=a%FFb');
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_an_unknown_record_is_a_json_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['company' => EntityId::generate(), 'contact' => 'not-a-uuid'] as $kind => $recordId) {
            $this->send('POST', self::ACTIVITIES, ['kind' => $kind, 'recordId' => $recordId, 'type' => 'note', 'body' => 'Text', 'occurredAt' => '2026-10-31T14:05']);
            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertArrayHasKey('', $this->errors());
        }
    }

    public function test_you_can_edit_your_own_entry(): void
    {
        $staff = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $activity = $this->entryBy($staff['user'], 'Alt');

        $this->send('PATCH', self::ACTIVITIES.'/'.$activity, ['body' => ' Neu ', 'occurredAt' => '2026-10-30T09:15']);

        self::assertResponseIsSuccessful();
        $entry = $this->entry();
        self::assertSame('Neu', $entry['body']);
        self::assertSame('2026-10-30T09:15', $entry['occurredAt']);
        self::assertTrue($entry['canEdit']);
        self::assertSame(1, $this->auditRows('activity', $activity));
    }

    public function test_an_edit_that_changes_nothing_leaves_no_audit_row(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $activity = $this->entryBy($staff['user'], 'Gleich', '2026-10-30 09:15:00+01');

        $this->send('PATCH', self::ACTIVITIES.'/'.$activity, ['body' => ' Gleich ', 'occurredAt' => '2026-10-30T09:15']);

        self::assertResponseIsSuccessful();
        self::assertSame('Gleich', $this->entry()['body']);
        self::assertSame(0, $this->auditRows('activity', $activity));
    }

    public function test_an_invalid_edit_is_a_422(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $activity = $this->entryBy($staff['user'], 'Alt');

        $this->send('PATCH', self::ACTIVITIES.'/'.$activity, ['body' => ' ', 'occurredAt' => 'gestern']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['body', 'occurredAt'], array_keys($this->errors()));
    }

    /**
     * @return iterable<string, array{AdminRole}>
     */
    public static function roles(): iterable
    {
        yield 'editor' => [AdminRole::Editor];
        yield 'admin' => [AdminRole::Admin];
    }

    #[DataProvider('roles')]
    public function test_an_entry_of_somebody_else_is_a_403(AdminRole $role): void
    {
        $this->logInAs($role);
        $activity = $this->entryBy(AdminUserFactory::createOne(), 'Fremd', '2026-10-30 09:15:00+01');

        $this->send('PATCH', self::ACTIVITIES.'/'.$activity, ['body' => 'Gekapert', 'occurredAt' => '2026-10-30T09:15']);
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertArrayHasKey('', $this->errors());

        // The no-op answer may not tell a foreign entry from your own.
        $this->send('PATCH', self::ACTIVITIES.'/'.$activity, ['body' => 'Fremd', 'occurredAt' => '2026-10-30T09:15']);
        self::assertResponseStatusCodeSame(403);

        $this->send('DELETE', self::ACTIVITIES.'/'.$activity);
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        self::assertSame('Fremd', $this->connection->fetchOne('SELECT body FROM crm_activities WHERE id = ?', [$activity]));
        self::assertSame(0, $this->auditRows('activity', $activity));
    }

    public function test_an_anonymised_entry_is_a_403_for_its_author(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $activity = ActivityFactory::createOne(['actor' => $staff['user']]);
        $activity->anonymise(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $this->send('PATCH', self::ACTIVITIES.'/'.$activity->id(), ['body' => 'Wieder da', 'occurredAt' => '2026-10-30T09:15']);
        self::assertResponseStatusCodeSame(403);

        $this->send('DELETE', self::ACTIVITIES.'/'.$activity->id());
        self::assertResponseStatusCodeSame(403);
    }

    public function test_you_can_delete_your_own_entry(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $activity = $this->entryBy($staff['user'], 'Weg');

        $this->send('DELETE', self::ACTIVITIES.'/'.$activity);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activities WHERE id = ?', [$activity]));
        self::assertSame(1, $this->auditRows('activity', $activity));
    }

    public function test_a_missing_entry_is_a_json_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $url = self::ACTIVITIES.'/'.EntityId::generate();

        $this->send('PATCH', $url, ['body' => 'Text', 'occurredAt' => '2026-10-30T09:15']);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->send('DELETE', $url);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_missing_token_is_refused(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $activity = $this->entryBy($staff['user'], 'Bleibt');
        $headers = ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', self::ACTIVITIES, server: $headers, content: (string) json_encode(['kind' => 'company', 'recordId' => $company->id(), 'type' => 'note', 'body' => 'Text', 'occurredAt' => '2026-10-31T14:05']));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('PATCH', self::ACTIVITIES.'/'.$activity, server: $headers, content: '{"body": "Neu", "occurredAt": "2026-10-31T14:05"}');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', self::ACTIVITIES.'/'.$activity, server: $headers);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('Bleibt', $this->connection->fetchOne('SELECT body FROM crm_activities WHERE id = ?', [$activity]));
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $company = CompanyFactory::createOne();

        $this->client->request('GET', $this->timelineUrl('companies', $company->id()));

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_page_has_the_newest_entries_and_a_cursor_to_the_next(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $expected = [];
        for ($day = 1; $day <= TimelineQuery::PER_PAGE + 3; ++$day) {
            array_unshift($expected, ActivityFactory::new()->onCompany($company)->create(['occurredAt' => (new \DateTimeImmutable('2026-01-01 10:00:00+00'))->modify('+'.$day.' days')])->id());
        }
        $url = $this->timelineUrl('companies', $company->id());

        $first = $this->page($url);
        self::assertSame(\array_slice($expected, 0, TimelineQuery::PER_PAGE), array_column($first['entries'], 'id'));
        self::assertIsString($first['next']);

        $second = $this->page($url.'?before='.urlencode($first['next']));
        self::assertSame(\array_slice($expected, TimelineQuery::PER_PAGE), array_column($second['entries'], 'id'));
        self::assertNull($second['next']);
    }

    public function test_a_cursor_on_an_old_entry_still_reaches_the_next_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $ids = [];
        for ($day = 1; $day <= TimelineQuery::PER_PAGE + 1; ++$day) {
            array_unshift($ids, ActivityFactory::new()->onCompany($company)->create(['occurredAt' => (new \DateTimeImmutable('1950-01-01 10:00:00+00'))->modify('+'.$day.' days')])->id());
        }
        $url = $this->timelineUrl('companies', $company->id());

        $first = $this->page($url);
        self::assertIsString($first['next']);

        self::assertSame([$ids[TimelineQuery::PER_PAGE]], array_column($this->page($url.'?before='.urlencode($first['next']))['entries'], 'id'));
    }

    public function test_the_type_filter_keeps_that_kind_and_drops_the_system_events(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $venue = VenueFactory::createOne();
        $this->entityManager()->persist(new CompanyVenue($company, $venue));
        $this->entityManager()->flush();
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => 'console',
            'command' => PublishVenue::class,
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{"status": "published"}',
            'created_at' => '2026-10-21 09:00:00+00',
        ]);
        ActivityFactory::new()->onCompany($company)->create();
        $call = ActivityFactory::new()->ofType(ActivityType::CALL)->onCompany($company)->create();
        $url = $this->timelineUrl('companies', $company->id());

        self::assertSame([$call->id()], $this->idsOf($url.'?type=call'));
        self::assertSame([$call->id()], $this->idsOf($url.'?type=call&before=garbage'));

        $everything = $this->page($url.'?type=');
        self::assertCount(3, $everything['entries']);
        $system = array_values(array_filter($everything['entries'], static fn (array $entry): bool => 'system' === $entry['kind']));
        self::assertCount(1, $system);
        self::assertSame('published', $system[0]['type']);
        self::assertSame($venue->name(), $system[0]['venueName']);
        self::assertSame($venue->number(), $system[0]['venueNumber']);
        self::assertFalse($system[0]['canEdit']);
    }

    public function test_an_unknown_type_is_a_422_on_the_type(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $url = $this->timelineUrl('companies', $company->id());

        foreach (['?type=fax', '?type=published', '?type[]=note', '?type=%00'] as $query) {
            $this->client->request('GET', $url.$query);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['type'], array_keys($this->errors()), $query);
        }
    }

    public function test_a_cursor_that_is_an_array_is_the_first_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $activity = ActivityFactory::new()->onCompany($company)->create();

        self::assertSame([$activity->id()], $this->idsOf($this->timelineUrl('companies', $company->id()).'?before[]=x'));
    }

    public function test_a_timeline_says_per_entry_what_the_viewer_may_edit(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne();
        $mine = ActivityFactory::new()->onContact($contact)->create(['actor' => $staff['user'], 'occurredAt' => new \DateTimeImmutable('2026-10-21 09:00:00+00')]);
        ActivityFactory::new()->onContact($contact)->create(['actor' => AdminUserFactory::createOne(), 'occurredAt' => new \DateTimeImmutable('2026-10-20 09:00:00+00')]);

        $entries = $this->page($this->timelineUrl('contacts', $contact->id()))['entries'];

        self::assertSame([$mine->id()], array_column(array_filter($entries, static fn (array $entry): bool => $entry['canEdit']), 'id'));
    }

    public function test_an_unknown_record_has_no_timeline(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['companies', 'contacts'] as $segment) {
            $this->client->request('GET', $this->timelineUrl($segment, EntityId::generate()));
            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
        }
    }

    private function entryBy(AdminUser $author, string $body, string $occurredAt = '2026-10-20 12:00:00+02'): string
    {
        return ActivityFactory::createOne(['actor' => $author, 'body' => $body, 'occurredAt' => new \DateTimeImmutable($occurredAt)])->id();
    }

    private function timelineUrl(string $segment, string $id): string
    {
        return '/admin/api/crm/'.$segment.'/'.$id.'/timeline';
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $url, ?array $body = null): void
    {
        $this->sendRaw($method, $url, null === $body ? '' : (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function sendRaw(string $method, string $url, string $body): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $body);
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
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        $entry = $this->answer()['entry'] ?? null;
        self::assertIsArray($entry);

        /* @var array<string, mixed> $entry */
        return $entry;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, next: ?string}
     */
    private function page(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        /** @var array{entries: list<array<string, mixed>>, next: ?string} $page */
        $page = $this->answer();
        self::assertSame(['entries', 'next'], array_keys($page));

        return $page;
    }

    /**
     * @return list<mixed>
     */
    private function idsOf(string $url): array
    {
        return array_column($this->page($url)['entries'], 'id');
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

    private function auditRows(string $subjectType, string $subjectId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE subject_type = ? AND subject_id = ?', [$subjectType, $subjectId]);
    }
}
