<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\SendCrmEmail;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Mailer\CrmMailer;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Sending from a record through the real mailer, bus and transaction, on the test transport. `.env.test` sets the
 * override, so every mail reaches it and the entry keeps the address staff asked for. A dead SMTP has its own class,
 * because its DSN goes in before the client boots (`EmailApiDeliveryFailureTest`).
 */
final class EmailApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string URL = '/admin/api/crm/emails';
    private const string MAILBOX = 'hallo@hochzeitslocation.ch';
    private const string OVERRIDE = 'override@hochzeitslocation.test';
    private const string COMPANY_ADDRESS = 'info@seeblick.example';
    private const string CONTACT_ADDRESS = 'mia@example.ch';
    private const string CC = 'tom@example.ch';
    private const string SUBJECT = 'Ihre Offerte';
    private const string BODY = "Guten Tag\n\n**Danke** für Ihr Interesse.";

    public function test_a_mail_from_a_contact_answers_201_with_its_entry(): void
    {
        $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'contact', 'recordId' => $contact->id()]);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertEmailCount(1);
        $entry = $this->entry();
        self::assertIsString($entry['id']);
        self::assertIsString($entry['occurredAt']);
        unset($entry['id'], $entry['occurredAt']);
        self::assertSame([
            'kind' => 'activity',
            'type' => ActivityType::EMAIL_SENT,
            'actorName' => 'Andrea Keller',
            'title' => self::SUBJECT,
            'body' => self::BODY,
            'anonymised' => false,
            'canEdit' => false,
            'venueNumber' => null,
            'venueName' => null,
            'tier' => null,
            'stage' => null,
            'inquiryId' => null,
            'inquiryCouple' => null,
            'email' => ['to' => self::CONTACT_ADDRESS, 'cc' => null, 'status' => 'sent'],
        ], $entry);
    }

    public function test_a_contact_links_itself_and_its_company(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company, 'email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'contact', 'recordId' => $contact->id()]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSentTo(self::CONTACT_ADDRESS, [$contact->id()], [$company->id()]);
    }

    public function test_a_contact_without_a_company_links_itself_alone(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'contact', 'recordId' => $contact->id()]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSentTo(self::CONTACT_ADDRESS, [$contact->id()], []);
    }

    public function test_a_company_with_no_contact_chosen_uses_its_own_address(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();

        $this->mail(['kind' => 'company', 'recordId' => $company->id(), 'contactId' => null]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSentTo(self::COMPANY_ADDRESS, [], [$company->id()]);
    }

    public function test_a_contact_chosen_on_a_company_gets_the_mail_and_both_links(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();
        $contact = ContactFactory::createOne(['company' => $company, 'email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'company', 'recordId' => $company->id(), 'contactId' => $contact->id()]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSentTo(self::CONTACT_ADDRESS, [$contact->id()], [$company->id()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noContactChosen(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[DataProvider('noContactChosen')]
    public function test_an_empty_contact_id_is_the_company_s_own_address_not_a_404(string $contactId): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();

        $this->mail(['kind' => 'company', 'recordId' => $company->id(), 'contactId' => $contactId]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSentTo(self::COMPANY_ADDRESS, [], [$company->id()]);
    }

    /**
     * Past the `MessageListener` of the real mailer, whose `From` from `config/packages/mailer.yaml` only fills an
     * empty header. The override takes the CC with it, and the entry still says what staff asked for.
     */
    public function test_the_real_mailer_keeps_the_staff_name_on_the_site_s_address(): void
    {
        $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $contact = ContactFactory::createOne(['email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'contact', 'recordId' => $contact->id(), 'cc' => ' Tom@Example.CH ']);

        self::assertResponseStatusCodeSame(201);
        $mail = $this->sentMail();
        self::assertCount(1, $mail->getFrom());
        self::assertSame(self::MAILBOX, $mail->getFrom()[0]->getAddress());
        self::assertSame('Andrea Keller', $mail->getFrom()[0]->getName());
        self::assertSame([self::MAILBOX], self::addresses($mail->getReplyTo()));
        self::assertSame([self::OVERRIDE], self::addresses($mail->getTo()));
        self::assertSame([], $mail->getCc());
        self::assertSame(self::CONTACT_ADDRESS.', '.self::CC, $mail->getHeaders()->getHeaderBody(CrmMailer::ORIGINAL_RECIPIENT_HEADER));
        self::assertSame(self::SUBJECT, $mail->getSubject());
        self::assertSame(self::BODY, $mail->getTextBody());
        self::assertSame(['to' => self::CONTACT_ADDRESS, 'cc' => self::CC, 'status' => 'sent'], $this->entry()['email']);
    }

    public function test_twig_syntax_in_a_mail_stays_literal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'contact', 'recordId' => $contact->id(), 'body' => 'Rechnung: {{ 7*7 }}']);

        self::assertResponseStatusCodeSame(201);
        $html = (string) $this->sentMail()->getHtmlBody();
        self::assertStringContainsString('{{ 7*7 }}', $html);
        self::assertStringNotContainsString('Rechnung: 49', $html);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedInput(): iterable
    {
        yield 'an empty subject' => [['subject' => ''], 'subject'];
        yield 'a line break in the subject' => [['subject' => "Zeile\nzwei"], 'subject'];
        yield 'a subject over 200' => [['subject' => str_repeat('a', 201)], 'subject'];
        yield 'a bad cc' => [['cc' => 'keine-adresse'], 'cc'];
        yield 'two addresses in the cc' => [['cc' => 'a@example.ch, b@example.ch'], 'cc'];
        yield 'an empty body' => [['body' => '   '], 'body'];
        yield 'a body over 20000' => [['body' => str_repeat('a', 20001)], 'body'];
        yield 'a contact id that is no uuid' => [['contactId' => 'keine-uuid'], 'contactId'];
        yield 'a deal' => [['kind' => 'deal'], 'kind'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('refusedInput')]
    public function test_refused_input_is_a_422_on_its_field_and_sends_nothing(array $input, string $field): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();

        $this->mail($input + ['kind' => 'company', 'recordId' => $company->id()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$field], array_keys($this->errors()));
        $this->assertNothingHappened();
    }

    public function test_a_recipient_without_an_address_is_a_422_on_the_contact_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company, 'email' => null]);

        foreach ([
            ['kind' => 'company', 'recordId' => $company->id()],
            ['kind' => 'company', 'recordId' => $company->id(), 'contactId' => $contact->id()],
            ['kind' => 'contact', 'recordId' => $contact->id()],
        ] as $target) {
            $this->mail($target);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['contactId' => ['Für diesen Empfänger ist keine E-Mail-Adresse hinterlegt.']], $this->errors());
        }
        $this->assertNothingHappened();
    }

    /**
     * The existing `failure()` line answers on `value`, which the composer has no input for, so `formFailure()` shows
     * it in `message`: seen, and not under the recipient.
     */
    public function test_another_company_s_contact_is_a_422_on_value(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();
        $stranger = ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'company', 'recordId' => $company->id(), 'contactId' => $stranger->id()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value' => ['Dieser Kontakt gehört zu einer anderen Location.']], $this->errors());
        $this->assertNothingHappened();
    }

    public function test_a_contact_with_no_company_is_another_company_s_contact_too(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();
        $loose = ContactFactory::createOne(['email' => self::CONTACT_ADDRESS]);

        $this->mail(['kind' => 'company', 'recordId' => $company->id(), 'contactId' => $loose->id()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_an_unknown_record_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();

        foreach ([
            ['kind' => 'company', 'recordId' => EntityId::generate()],
            ['kind' => 'contact', 'recordId' => EntityId::generate()],
            ['kind' => 'company', 'recordId' => $company->id(), 'contactId' => EntityId::generate()],
        ] as $target) {
            $this->mail($target);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['' => ['Dieser Eintrag existiert nicht mehr.']], $this->errors());
        }
        $this->assertNothingHappened();
    }

    public function test_no_csrf_token_is_a_403(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = $this->companyWithAddress();

        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['kind' => 'company', 'recordId' => $company->id(), 'subject' => self::SUBJECT, 'body' => self::BODY]));

        self::assertResponseStatusCodeSame(403);
        $this->assertNothingHappened();
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $company = $this->companyWithAddress();

        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['kind' => 'company', 'recordId' => $company->id(), 'subject' => self::SUBJECT, 'body' => self::BODY]));

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertNothingHappened();
    }

    /**
     * @return iterable<string, array{AdminRole}>
     */
    public static function staff(): iterable
    {
        yield 'editor' => [AdminRole::Editor];
        yield 'admin' => [AdminRole::Admin];
    }

    #[DataProvider('staff')]
    public function test_both_roles_may_send(AdminRole $role): void
    {
        $this->logInAs($role);
        $company = $this->companyWithAddress();

        $this->mail(['kind' => 'company', 'recordId' => $company->id()]);

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * An error on the transport's event comes back from the mailer as `HandlerFailedException`, the pipeline's own form.
     */
    public function test_an_error_in_the_mail_pipeline_stores_a_failed_entry(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['email' => self::CONTACT_ADDRESS]);
        $this->failMail(queued: false);

        $this->mail(['kind' => 'contact', 'recordId' => $contact->id()]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['to' => self::CONTACT_ADDRESS, 'cc' => null, 'status' => 'failed'], $this->entry()['email']);
        self::assertSame('failed', $this->connection->fetchOne("SELECT payload->>'status' FROM crm_activities"));
    }

    /**
     * The queued event is where a render error of the template raises: the mailer lets it through unwrapped, so it is
     * no mail outcome. The client rethrows it so that the test sees it pass the controller.
     */
    public function test_an_error_before_the_mail_pipeline_bubbles_and_stores_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['email' => self::CONTACT_ADDRESS]);
        $this->failMail(queued: true);
        $this->client->catchExceptions(false);

        try {
            $this->mail(['kind' => 'contact', 'recordId' => $contact->id()]);
            self::fail('The error should have bubbled.');
        } catch (HandlerFailedException $failure) {
            self::assertSame('Kaputt.', $failure->getPrevious()?->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activities'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [SendCrmEmail::class]));
    }

    /**
     * Step 3.6: the read-only timeline says as much as the island, the subject and a failure too.
     */
    public function test_without_javascript_an_entry_shows_its_subject_and_a_failure(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne();
        $mail = ActivityFactory::new()->ofType(ActivityType::EMAIL_SENT)->onCompany($company);
        $mail->create([
            'occurredAt' => new \DateTimeImmutable('2026-10-22 09:00:00+00'),
            'actor' => $me,
            'title' => 'Angekommen',
            'body' => 'Guten Tag',
            'payload' => ['to' => self::CONTACT_ADDRESS, 'status' => 'sent'],
        ]);
        $mail->create([
            'occurredAt' => new \DateTimeImmutable('2026-10-21 09:00:00+00'),
            'actor' => $me,
            'title' => 'Hängen geblieben',
            'body' => 'Guten Abend',
            'payload' => ['to' => self::CONTACT_ADDRESS, 'status' => 'failed'],
        ]);
        $gone = $mail->create([
            'occurredAt' => new \DateTimeImmutable('2026-10-20 09:00:00+00'),
            'actor' => $me,
            'title' => 'Entfernt',
            'body' => 'Weg',
            'payload' => ['to' => self::CONTACT_ADDRESS, 'status' => 'sent'],
        ]);
        $gone->anonymise(new \DateTimeImmutable('2026-10-23 09:00:00+00'));
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $items = $crawler->filter('[data-island="timeline"] .timeline-item--card');
        self::assertCount(3, $items);
        self::assertStringContainsString('E-Mail gesendet', $items->eq(0)->filter('.timeline-item__title')->text());
        self::assertStringContainsString('Andrea Keller', $items->eq(0)->filter('.timeline-item__title')->text());
        self::assertSame('Angekommen Guten Tag', $items->eq(0)->filter('.muted')->text());
        self::assertStringNotContainsString('Nicht gesendet', $items->eq(0)->filter('.timeline-item__meta')->text());
        self::assertStringContainsString('Nicht gesendete E-Mail', $items->eq(1)->filter('.timeline-item__title')->text());
        self::assertSame('Hängen geblieben Guten Abend', $items->eq(1)->filter('.muted')->text());
        self::assertStringContainsString('Nicht gesendet', $items->eq(1)->filter('.timeline-item__meta')->text());
        self::assertSame('Inhalt entfernt', $items->eq(2)->filter('.muted')->text());
        self::assertStringNotContainsString('Nicht gesendet', $items->eq(2)->filter('.timeline-item__meta')->text());
    }

    private function companyWithAddress(): Company
    {
        return CompanyFactory::new()->afterInstantiate(static fn (Company $company) => $company->changeEmail(self::COMPANY_ADDRESS))->create();
    }

    /**
     * @param array<string, string|null> $fields
     */
    private function mail(array $fields): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode($fields + ['subject' => self::SUBJECT, 'body' => self::BODY], \JSON_THROW_ON_ERROR));
    }

    private function failMail(bool $queued): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event) use ($queued): void {
            if ($queued === $event->isQueued()) {
                throw new \RuntimeException('Kaputt.');
            }
        });
    }

    /**
     * @param list<string> $contacts
     * @param list<string> $companies
     */
    private function assertSentTo(string $address, array $contacts, array $companies): void
    {
        self::assertEmailCount(1);
        $entry = $this->entry();
        self::assertSame(['to' => $address, 'cc' => null, 'status' => 'sent'], $entry['email']);
        self::assertSame($contacts, $this->connection->fetchFirstColumn('SELECT contact_id FROM crm_activity_contacts WHERE activity_id = ?', [$entry['id']]));
        self::assertSame($companies, $this->connection->fetchFirstColumn('SELECT company_id FROM crm_activity_companies WHERE activity_id = ?', [$entry['id']]));
    }

    private function assertNothingHappened(): void
    {
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activities'));
    }

    private function sentMail(): Email
    {
        self::assertEmailCount(1);
        $sent = array_values(array_filter(self::getMailerEvents(), static fn (MessageEvent $event): bool => !$event->isQueued()));
        $mail = $sent[0]->getMessage();
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $addresses));
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
     * @return array<string, mixed>
     */
    private function errors(): array
    {
        $errors = $this->answer()['errors'] ?? null;
        self::assertIsArray($errors);

        /* @var array<string, mixed> $errors */
        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        /* @var array<string, mixed> $answer */
        return $answer;
    }
}
