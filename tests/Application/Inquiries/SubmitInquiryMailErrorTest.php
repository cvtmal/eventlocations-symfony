<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Mail errors that are not transport errors, through the real mailer, bus and transaction
 * middleware. A `MessageEvent` listener throws where the pipeline can fail: on the queued event
 * the mailer raises it unwrapped, as it does a render error of the template; on the transport's
 * event it comes back as `HandlerFailedException`, as the SMTP transport's own errors do
 * (`DeliverInquiryOverSmtpTest` has those for real).
 */
final class SubmitInquiryMailErrorTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    private const SLUG = DetailVenues::FULL_SLUG;
    private const OVERRIDE = 'override@hochzeitslocation.test';
    private const GUEST = 'anna@example.ch';
    private const IN_THE_BUS = false;
    private const BEFORE_THE_BUS = true;

    protected function setUp(): void
    {
        $this->start();
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function failurePoints(): iterable
    {
        yield 'in the bus' => [self::IN_THE_BUS];
        yield 'before the bus' => [self::BEFORE_THE_BUS];
    }

    #[DataProvider('failurePoints')]
    public function test_a_failed_guest_copy_cannot_undo_the_sent_venue_mail(bool $point): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();
        $this->failMailTo(self::GUEST, $point);

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        $this->assertPrivate();
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('sent', $rows[0]['venue_mail_status']);
        self::assertSame('failed', $rows[0]['guest_mail_status']);
        self::assertSame(self::OVERRIDE, $rows[0]['delivered_to']);
        self::assertNotNull($rows[0]['venue_mail_sent_at']);
        self::assertSame([$rows[0]['id']], $received->ids);
        self::assertEmailCount(1);
    }

    public function test_a_venue_mail_error_in_the_bus_tells_the_guest_and_keeps_everything(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();
        $this->failMailTo(self::OVERRIDE, self::IN_THE_BUS);

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid(['lastName' => 'Behalten']));

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivate();
        self::assertStringStartsWith('Deine Anfrage ist bei uns gespeichert', $crawler->filter('#anfrage [role="alert"]')->text());
        self::assertCount(0, $crawler->filter('#anfrage form'));
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['venue_mail_status']);
        self::assertSame('skipped', $rows[0]['guest_mail_status']);
        self::assertNull($rows[0]['delivered_to']);
        self::assertSame([$rows[0]['id']], $received->ids);
        self::assertEmailCount(0);
    }

    /**
     * R3, G7 (D56): nothing has left and the stored inquiry is still published. The error no longer
     * bubbles: the row is stored, so the guest gets the "stored, we deliver it" state, not a 500.
     */
    public function test_an_unexpected_error_before_the_venue_mail_still_publishes(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();
        $this->failMailTo(self::OVERRIDE, self::BEFORE_THE_BUS);
        $this->client->catchExceptions(false);

        $crawler = $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivate();
        self::assertStringStartsWith('Deine Anfrage ist bei uns gespeichert', $crawler->filter('#anfrage [role="alert"]')->text());
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['venue_mail_status']);
        self::assertSame('pending', $rows[0]['guest_mail_status']);
        self::assertSame([$rows[0]['id']], $received->ids);
        self::assertEmailCount(0);
    }

    public function test_a_retry_after_a_failed_venue_mail_delivers_the_same_row(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();
        $this->failMailTo(self::OVERRIDE, self::IN_THE_BUS, once: true);

        $this->post(self::SLUG, InquiryFormFields::valid());
        self::assertResponseStatusCodeSame(503);

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(2);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('sent', $rows[0]['venue_mail_status']);
        self::assertSame('sent', $rows[0]['guest_mail_status']);
        self::assertSame(self::OVERRIDE, $rows[0]['delivered_to']);
        self::assertSame([$rows[0]['id']], $received->ids);
    }

    /**
     * Nobody holds the row and nothing left, so the guest must not get a confirmation for it.
     */
    public function test_a_retry_after_an_unexpected_error_delivers_the_same_row(): void
    {
        DetailVenues::full($this->em);
        $received = $this->listen();
        $this->failMailTo(self::OVERRIDE, self::BEFORE_THE_BUS, once: true);

        $this->post(self::SLUG, InquiryFormFields::valid());
        self::assertResponseStatusCodeSame(503);

        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(2);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('sent', $rows[0]['venue_mail_status']);
        self::assertSame([$rows[0]['id']], $received->ids);
    }

    public function test_a_repeat_of_a_failed_guest_copy_sends_nothing(): void
    {
        DetailVenues::full($this->em);
        $this->failMailTo(self::GUEST, self::IN_THE_BUS, once: true);

        $this->post(self::SLUG, InquiryFormFields::valid());
        $this->post(self::SLUG, InquiryFormFields::valid());

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['guest_mail_status']);
    }

    private function failMailTo(string $recipient, bool $point, bool $once = false): void
    {
        $failed = false;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event) use ($recipient, $point, $once, &$failed): void {
            $message = $event->getMessage();
            if ($event->isQueued() !== $point || !$message instanceof Email || ($once && $failed)) {
                return;
            }

            foreach ($message->getTo() as $to) {
                if ($to->getAddress() === $recipient) {
                    $failed = true;

                    throw new \RuntimeException('A mail error.');
                }
            }
        });
    }
}
