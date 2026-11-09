<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Inquiries\Event\InquirySubmitted;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Mailer\Event\MessageEvent;
use Zenstruck\Foundry\Test\Factories;

/**
 * SMTP down, through the real pipeline: nothing listens on port 1, so the transport fails.
 * The DSN is read when the container builds the transport, so it is set before the client
 * boots, and put back afterwards. Either way the guest sees the G7 (D56) "stored, we deliver it"
 * state and no form.
 */
final class SubmitInquiryDeliveryFailureTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    private const DEAD_DSN = 'smtp://127.0.0.1:1';

    private const string UNDELIVERED = 'Deine Anfrage ist bei uns gespeichert, konnte aber gerade nicht an Schloss Seeblick zugestellt werden. Wir kümmern uns darum und stellen sie so bald wie möglich zu. Du musst nichts weiter tun.';

    private mixed $serverDsn;
    private mixed $envDsn;

    protected function setUp(): void
    {
        $this->serverDsn = $_SERVER['MAILER_DSN'] ?? null;
        $this->envDsn = $_ENV['MAILER_DSN'] ?? null;
        $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = self::DEAD_DSN;

        $this->start();
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();

        $_SERVER['MAILER_DSN'] = $this->serverDsn;
        $_ENV['MAILER_DSN'] = $this->envDsn;
    }

    public function test_a_failed_venue_mail_tells_the_guest_and_keeps_everything(): void
    {
        DetailVenues::full($this->em);
        $received = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(InquirySubmitted::class, static function (InquirySubmitted $event) use (&$received): void {
            $received[] = $event->inquiryId;
        });

        $funnelBefore = (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events');
        $crawler = $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['lastName' => 'Behalten']));

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivate();
        self::assertSame(self::UNDELIVERED, $crawler->filter('#anfrage [role="alert"]')->text());
        self::assertSame('-1', $crawler->filter('#anfrage-hinweis[role="alert"]')->attr('tabindex'), 'the POST fragment is the focusable notice');
        self::assertStringNotContainsString('Bitte prüfe deine Angaben.', $crawler->filter('#anfrage')->text());
        self::assertCount(0, $crawler->filter('#anfrage form'), 'nothing to correct, and a resubmit would duplicate');

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['venue_mail_status']);
        self::assertSame('skipped', $rows[0]['guest_mail_status']);
        self::assertSame('Behalten', $rows[0]['last_name']);
        self::assertSame('unqualified', $rows[0]['qualification']);
        self::assertSame('not_delivered', $rows[0]['qualification_reason']);
        self::assertNull($rows[0]['delivered_to']);
        self::assertNull($rows[0]['venue_mail_sent_at']);
        self::assertSame([$rows[0]['id']], $received);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ?', [$rows[0]['id']]));
        self::assertSame($funnelBefore, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'), 'nothing was delivered, so no funnel step (G8)');
    }

    /**
     * Not a transport error: a listener of the mail pipeline throws something the handler does not expect. The row
     * is stored by then, so the guest gets the same state instead of a 500.
     */
    public function test_an_unexpected_delivery_error_shows_the_same_state(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (): void {
            throw new \LogicException('Geheimnis aus der Ausnahme');
        });
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->enableDebug();

        $crawler = $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['lastName' => 'Unerwartet']));

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivate();
        self::assertSame(self::UNDELIVERED, $crawler->filter('#anfrage [role="alert"]')->text());
        self::assertCount(0, $crawler->filter('#anfrage form'));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['venue_mail_status'], 'the hourly staff alert picks it up');
        self::assertSame('Unerwartet', $rows[0]['last_name']);
        self::assertSame($received->ids, [$rows[0]['id']]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ?', [$rows[0]['id']]));

        $errors = array_values(array_filter($logger->getLogs(), static fn (array $log): bool => 'error' === strtolower((string) $log['priorityName']) && str_contains($log['message'], 'delivery ended unexpectedly')));
        self::assertCount(1, $errors);
        self::assertSame(\LogicException::class, $errors[0]['context']['exception_class']);
        self::assertStringNotContainsString('Geheimnis', print_r($logger->getLogs(), true), 'placeholders only, never the message');
    }
}
