<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Mailer\InquiryMailer;
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
        $inquiry = $this->inquiry([
            'eventDate' => new \DateTimeImmutable('2026-09-12'),
            'dateFlexible' => false,
            'phone' => '044 123 45 67',
            'guestCount' => 1200,
            'company' => 'Muster AG',
            'startTime' => new \DateTimeImmutable('1970-01-01 18:30'),
            'cateringWish' => 'in_house',
            'budgetChf' => 25000,
        ]);

        $deliveredTo = $this->mailer()->sendToVenue($inquiry);

        self::assertSame(self::OVERRIDE, $deliveredTo);
        $mail = $this->sentMail();
        self::assertSame([self::OVERRIDE], $this->addresses($mail->getTo()));
        self::assertSame((string) $inquiry->recipientEmail(), $mail->getHeaders()->getHeaderBody(InquiryMailer::ORIGINAL_RECIPIENT_HEADER));
        self::assertSame(['anna@example.ch'], $this->addresses($mail->getReplyTo()));
        self::assertSame('Anna Muster', $mail->getReplyTo()[0]->getName());
        self::assertSame(['hallo@deine-eventlocation.ch'], $this->addresses($mail->getFrom()));
        self::assertSame('deine-eventlocation.ch', $mail->getFrom()[0]->getName());
        self::assertSame("Neue Anfrage für Schloss Seeblick: Anna Muster, Firmenanlass, 12.09.2026, 1'200 Gäste", $mail->getSubject());

        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Firmenanlass', $html);
        self::assertStringContainsString('12.09.2026', $html);
        self::assertStringContainsString('044 123 45 67', $html);
        self::assertStringContainsString('Ist der Termin frei?', $html);

        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Anlass: Firmenanlass', $text);
        self::assertStringContainsString('Wunschtermin: 12.09.2026', $text);
        self::assertStringNotContainsString('flexibel', $text);
        self::assertStringContainsString("Gäste: 1'200", $text);
        self::assertStringContainsString('Beginn: 18:30 Uhr', $text);
        self::assertStringContainsString('Catering-Wunsch: Hauseigenes Catering', $text);
        self::assertStringContainsString("Budget: CHF 25'000", $text);
        self::assertStringContainsString('Firma: Muster AG', $text);
        self::assertStringContainsString('Ist der Termin frei?', $text);
        self::assertStringNotContainsString('<', $text);
    }

    public function test_a_flexible_date_says_so_and_empty_optional_facts_show_no_row(): void
    {
        $this->mailer()->sendToVenue($this->inquiry(['eventDate' => null, 'dateFlexible' => true, 'phone' => null]));

        $mail = $this->sentMail();
        self::assertStringContainsString(', flexibel,', (string) $mail->getSubject());
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Wunschtermin: flexibel', $text);
        foreach (['Telefon', 'Firma', 'Beginn', 'Catering', 'Budget'] as $label) {
            self::assertStringNotContainsString($label, $text);
        }
    }

    public function test_a_date_that_is_flexible_shows_both(): void
    {
        $this->mailer()->sendToVenue($this->inquiry(['eventDate' => new \DateTimeImmutable('2026-09-12'), 'dateFlexible' => true, 'cateringWish' => 'external_allowed']));

        $mail = $this->sentMail();
        self::assertStringContainsString(', 12.09.2026 (flexibel),', (string) $mail->getSubject());
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Wunschtermin: 12.09.2026 (flexibel)', $text);
        self::assertStringContainsString('Catering-Wunsch: Externes Catering erlaubt', $text);
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
        self::assertSame(['hallo@deine-eventlocation.ch'], $this->addresses($mail->getReplyTo()));
        self::assertSame(['hallo@deine-eventlocation.ch'], $this->addresses($mail->getFrom()));
        self::assertSame('Deine Anfrage an Schloss Seeblick', $mail->getSubject());
        self::assertFalse($mail->getHeaders()->has(InquiryMailer::ORIGINAL_RECIPIENT_HEADER));
        self::assertStringContainsString('Danke, Anna!', (string) $mail->getTextBody());
        self::assertStringContainsString('Ist der Termin frei?', (string) $mail->getTextBody());
        self::assertStringContainsString('Zur Location: http', (string) $mail->getTextBody());
        self::assertStringContainsString('/eventlokal/schloss-seeblick', (string) $mail->getTextBody());
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

    public function test_venue_mail_embeds_the_logo(): void
    {
        $this->mailer()->sendToVenue($this->inquiry());

        $this->assertLogoIsEmbedded($this->sentMail());
    }

    public function test_guest_copy_embeds_the_logo(): void
    {
        $this->mailer()->sendToGuest($this->inquiry());

        $this->assertLogoIsEmbedded($this->sentMail());
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
            'eventType' => 'corporate_event',
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

        return new InquiryMailer($recorder, $translator, $format, 'hallo@deine-eventlocation.ch', $override);
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

    private function assertLogoIsEmbedded(Email $mail): void
    {
        $parts = $mail->getAttachments();
        self::assertCount(1, $parts);
        $logo = array_values($parts)[0];
        self::assertSame('inline', $logo->getDisposition());
        self::assertSame('image/png', $logo->getContentType());

        $pattern = '/<img src="cid:'.preg_quote($logo->getContentId(), '/').'"[^>]*>/';
        $found = preg_match($pattern, (string) $mail->getHtmlBody(), $image);
        self::assertSame(1, $found);
        self::assertStringContainsString('alt="deine-eventlocation.ch"', $image[0]);
        self::assertStringContainsString('width="215"', $image[0]);
        self::assertStringContainsString('height="24"', $image[0]);
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
