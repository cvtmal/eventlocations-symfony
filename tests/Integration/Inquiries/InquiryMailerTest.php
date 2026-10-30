<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Mailer\InquiryMailer;
use App\Inquiries\ValueObject\Occasion;
use App\Shared\Twig\SwissFormatExtension;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\RecordingMailer;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The service from the container goes through the real pipeline (From, rendering, text part)
 * and carries .env.test's override. The real recipient needs a mailer built by hand.
 */
final class InquiryMailerTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const OVERRIDE = 'override@hochzeitslocation.test';

    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_venue_mail_through_the_pipeline(): void
    {
        $inquiry = $this->inquiry(['eventDate' => new \DateTimeImmutable('2026-09-12'), 'phone' => '044 123 45 67', 'guestCount' => 1200]);

        $deliveredTo = $this->mailer()->sendToVenue($inquiry);

        self::assertSame(self::OVERRIDE, $deliveredTo);
        $mail = $this->sentMail();
        self::assertSame([self::OVERRIDE], $this->addresses($mail->getTo()));
        self::assertSame((string) $inquiry->recipientEmail(), $mail->getHeaders()->getHeaderBody(InquiryMailer::ORIGINAL_RECIPIENT_HEADER));
        self::assertSame(['anna@example.ch'], $this->addresses($mail->getReplyTo()));
        self::assertSame('Anna Muster', $mail->getReplyTo()[0]->getName());
        self::assertSame(['hallo@hochzeitslocation.ch'], $this->addresses($mail->getFrom()));
        self::assertSame('Hochzeitslocation', $mail->getFrom()[0]->getName());
        self::assertSame("Neue Anfrage für Schloss Seeblick: Anna Muster, 12.09.2026, 1'200 Gäste", $mail->getSubject());

        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Hochzeit', $html);
        self::assertStringContainsString('12.09.2026', $html);
        self::assertStringContainsString('044 123 45 67', $html);
        self::assertStringContainsString('Ist der Termin frei?', $html);

        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Anlass: Hochzeit', $text);
        self::assertStringContainsString('Wunschtermin: 12.09.2026', $text);
        self::assertStringContainsString("Gäste: 1'200", $text);
        self::assertStringContainsString('Ist der Termin frei?', $text);
        self::assertStringNotContainsString('<', $text);
    }

    public function test_an_open_date_says_so_and_no_phone_shows_no_row(): void
    {
        $this->mailer()->sendToVenue($this->inquiry(['eventDate' => null, 'phone' => null]));

        $mail = $this->sentMail();
        self::assertStringContainsString(', offen,', (string) $mail->getSubject());
        self::assertStringContainsString('Wunschtermin: offen', (string) $mail->getTextBody());
        self::assertStringNotContainsString('Telefon', (string) $mail->getTextBody());
    }

    public function test_free_text_cannot_inject_markup(): void
    {
        $this->mailer()->sendToVenue($this->inquiry(['message' => '<script>alert(1)</script> & <b>fett</b>', 'firstName' => '<i>Anna</i>']));

        $html = (string) $this->sentMail()->getHtmlBody();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>fett</b>', $html);
        self::assertStringNotContainsString('<i>Anna</i>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_line_breaks_of_the_message_are_kept(): void
    {
        $this->mailer()->sendToVenue($this->inquiry(['message' => "Erste Zeile\r\nZweite Zeile"]));

        $mail = $this->sentMail();
        self::assertStringContainsString('<br', (string) $mail->getHtmlBody());
        self::assertStringContainsString("Erste Zeile\nZweite Zeile", (string) $mail->getTextBody());
    }

    public function test_guest_copy_is_not_redirected(): void
    {
        $inquiry = $this->inquiry();

        $this->mailer()->sendToGuest($inquiry);

        $mail = $this->sentMail();
        self::assertSame(['anna@example.ch'], $this->addresses($mail->getTo()));
        self::assertSame(['hallo@hochzeitslocation.ch'], $this->addresses($mail->getReplyTo()));
        self::assertSame(['hallo@hochzeitslocation.ch'], $this->addresses($mail->getFrom()));
        self::assertSame('Deine Anfrage an Schloss Seeblick', $mail->getSubject());
        self::assertFalse($mail->getHeaders()->has(InquiryMailer::ORIGINAL_RECIPIENT_HEADER));
        self::assertStringContainsString('Danke, Anna!', (string) $mail->getTextBody());
        self::assertStringContainsString('Ist der Termin frei?', (string) $mail->getTextBody());
        self::assertStringContainsString('Zur Location: http', (string) $mail->getTextBody());
        self::assertStringContainsString('/hochzeitslocation/schloss-seeblick', (string) $mail->getTextBody());
    }

    public function test_venue_mail_goes_to_the_venue_without_an_override(): void
    {
        foreach ([null, ''] as $override) {
            $inquiry = $this->inquiry();
            $recorder = new RecordingMailer();

            $deliveredTo = $this->handBuilt($recorder, $override)->sendToVenue($inquiry);

            self::assertSame($inquiry->recipientEmail(), $deliveredTo);
            self::assertSame([$inquiry->recipientEmail()], $this->addresses($recorder->messages[0]->getTo()));
            self::assertFalse($recorder->messages[0]->getHeaders()->has(InquiryMailer::ORIGINAL_RECIPIENT_HEADER));
        }
    }

    public function test_a_header_in_the_name_stays_out_of_the_headers(): void
    {
        $inquiry = $this->inquiry(['lastName' => "Muster\r\nBcc: x@example.ch"]);
        $recorder = new RecordingMailer();

        $this->handBuilt($recorder, null)->sendToVenue($inquiry);

        $message = $recorder->messages[0];
        self::assertFalse($message->getHeaders()->has('Bcc'));
        self::assertSame([], $message->getBcc());
        $headers = $message->getHeaders()->toString();
        self::assertSame(0, preg_match('/^Bcc:/mi', $headers), $headers);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function inquiry(array $attributes = []): Inquiry
    {
        $venue = repository(Venue::class)->findOneBy(['name' => 'Schloss Seeblick']) ?? VenueFactory::new()->withContact()->create(['name' => 'Schloss Seeblick']);

        return InquiryFactory::createOne($attributes + [
            'venue' => $venue,
            'venueSlug' => 'schloss-seeblick',
            'occasion' => Occasion::Wedding,
            'guestCount' => 120,
            'message' => 'Ist der Termin frei?',
            'firstName' => 'Anna',
            'lastName' => 'Muster',
            'email' => 'anna@example.ch',
        ]);
    }

    private function mailer(): InquiryMailer
    {
        $mailer = self::getContainer()->get(InquiryMailer::class);
        self::assertInstanceOf(InquiryMailer::class, $mailer);

        return $mailer;
    }

    private function handBuilt(RecordingMailer $recorder, ?string $override): InquiryMailer
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $format = self::getContainer()->get(SwissFormatExtension::class);
        self::assertInstanceOf(SwissFormatExtension::class, $format);

        return new InquiryMailer($recorder, $translator, $format, 'hallo@hochzeitslocation.ch', $override);
    }

    private function sentMail(): Email
    {
        $events = array_values(array_filter(
            self::getMailerEvents(),
            static fn (MessageEvent $event): bool => !$event->isQueued(),
        ));
        self::assertCount(1, $events);
        $message = $events[0]->getMessage();
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $addresses));
    }
}
