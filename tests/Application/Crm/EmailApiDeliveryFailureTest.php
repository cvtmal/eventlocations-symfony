<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\SendCrmEmail;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Log\Logger;
use Zenstruck\Foundry\Test\Factories;

/**
 * SMTP down, through the real pipeline: nothing listens on port 1, so the transport fails. The DSN is read when the
 * container builds the transport, so it is set before the client boots and put back afterwards, as in
 * `SubmitInquiryDeliveryFailureTest`.
 */
final class EmailApiDeliveryFailureTest extends WebTestCase
{
    use AdminSession {
        setUp as private startSession;
        tearDown as private endSession;
    }
    use Factories;

    private const string DEAD_DSN = 'smtp://127.0.0.1:1';
    private const string ADDRESS = 'mia@example.ch';
    private const string CC = 'tom@example.ch';

    private mixed $serverDsn;
    private mixed $envDsn;

    protected function setUp(): void
    {
        $this->serverDsn = $_SERVER['MAILER_DSN'] ?? null;
        $this->envDsn = $_ENV['MAILER_DSN'] ?? null;
        $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = self::DEAD_DSN;

        $this->startSession();
    }

    protected function tearDown(): void
    {
        $this->endSession();

        $_SERVER['MAILER_DSN'] = $this->serverDsn;
        $_ENV['MAILER_DSN'] = $this->envDsn;
    }

    public function test_a_dead_smtp_stores_a_failed_entry_and_logs_no_address(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company, 'email' => self::ADDRESS]);
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->enableDebug();

        $crawler = $this->client->request('GET', '/admin');
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
        $this->client->request('POST', '/admin/api/crm/emails', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode([
            'kind' => 'company',
            'recordId' => $company->id(),
            'contactId' => $contact->id(),
            'cc' => self::CC,
            'subject' => 'Ihre Offerte',
            'body' => 'Guten Tag',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['entry']);
        self::assertSame(['to' => self::ADDRESS, 'cc' => self::CC, 'status' => 'failed'], $answer['entry']['email']);
        $id = $answer['entry']['id'];
        self::assertIsString($id);

        self::assertSame(
            ['type_code' => 'email_sent', 'title' => 'Ihre Offerte', 'body' => 'Guten Tag', 'status' => 'failed'],
            $this->connection->fetchAssociative("SELECT type_code, title, body, payload->>'status' AS status FROM crm_activities WHERE id = ?", [$id]),
        );
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ?', [$id]));
        self::assertSame(
            '{"type": "email_sent"}',
            $this->connection->fetchOne('SELECT changes::text FROM admin_audit_log WHERE command = ? AND subject_id = ?', [SendCrmEmail::class, $id]),
            'A failed send is audited too (D12), with the type only.',
        );

        $log = print_r($logger->getLogs(), true);
        self::assertStringContainsString('Crm mail {activity} failed', $log);
        self::assertStringContainsString($id, $log);
        foreach ([self::ADDRESS, self::CC, 'Ihre Offerte', 'Guten Tag'] as $personal) {
            self::assertStringNotContainsString($personal, $log);
        }
    }
}
