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
use Zenstruck\Foundry\Test\Factories;

/**
 * SMTP down, through the real pipeline: nothing listens on port 1, so the transport fails.
 * The DSN is read when the container builds the transport, so it is set before the client
 * boots, and put back afterwards.
 */
final class SubmitInquiryDeliveryFailureTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    private const DEAD_DSN = 'smtp://127.0.0.1:1';

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

        $crawler = $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['lastName' => 'Behalten']));

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivate();
        self::assertSelectorTextContains('#anfrage [role="alert"]', 'konnte nicht zugestellt werden');
        self::assertSame('Behalten', $crawler->filter('#inquiry_lastName')->attr('value'));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['venue_mail_status']);
        self::assertSame('skipped', $rows[0]['guest_mail_status']);
        self::assertNull($rows[0]['delivered_to']);
        self::assertNull($rows[0]['venue_mail_sent_at']);
        self::assertSame([$rows[0]['id']], $received);
    }
}
