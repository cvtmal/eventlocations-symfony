<?php

declare(strict_types=1);

namespace App\Tests\Application\Inquiries;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PhoneNumber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * A guard for F12's contact rule: a venue on a plan without contact data (Free) shows neither its phone nor its
 * website, and a mail is a page too. Both mails of an inquiry, the one to the venue and the confirmation to the
 * organizer, carry none of them today. A later cycle that adds venue contact lines to a mail (F9) must ask
 * `EntitlementResolver::forVenue()` first, and this test is the one that fails when it does not.
 */
final class InquiryMailsKeepFreeContactDataOutTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    private const string SLUG = 'haus-klein';

    protected function setUp(): void
    {
        $this->start();
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    public function test_the_mails_of_a_free_venue_carry_neither_its_phone_nor_its_website(): void
    {
        DetailVenues::overfull(KnownTiers::FREE, self::SLUG, 'Haus Klein');

        // The organizer's own number is another one, so the guard cannot mistake it for the venue's.
        $this->post(self::SLUG, InquiryFormFields::valid(['phone' => '079 555 12 34']));

        self::assertResponseStatusCodeSame(303);
        $mails = $this->sentMails();
        self::assertCount(2, $mails);
        self::assertStringStartsWith('Neue Anfrage für Haus Klein', (string) $mails[0]->getSubject(), 'the mail to the venue');
        self::assertSame('Deine Anfrage an Haus Klein', $mails[1]->getSubject(), 'the confirmation to the organizer');
        foreach ($mails as $mail) {
            $content = $this->contentOf($mail);
            self::assertStringContainsString('Haus Klein', $content, 'the guard reads the rendered mail');
            foreach ($this->venueContactData() as $what => $value) {
                self::assertStringNotContainsString($value, $content, \sprintf('"%s" of a Free venue is in the mail "%s"', $what, (string) $mail->getSubject()));
            }
        }
    }

    public function test_the_guard_sees_the_organizers_own_number_in_the_venue_mail(): void
    {
        DetailVenues::overfull(KnownTiers::FREE, self::SLUG, 'Haus Klein');

        $this->post(self::SLUG, InquiryFormFields::valid(['phone' => '079 555 12 34']));

        $content = $this->contentOf($this->sentMails()[0]);
        self::assertStringContainsString('079 555 12 34', $content);
    }

    /**
     * @return array<string, string>
     */
    private function venueContactData(): array
    {
        return [
            'phone (E.164)' => DetailVenues::FULL_PHONE,
            'phone (digits)' => '441234567',
            'phone (display)' => PhoneNumber::fromE164(DetailVenues::FULL_PHONE)->display(),
            'website' => 'https://ueberfluss.example',
            'website (escaped)' => 'https:\/\/ueberfluss.example',
        ];
    }

    /**
     * @return list<Email> in the order they were sent
     */
    private function sentMails(): array
    {
        $mails = [];
        foreach (array_filter(self::getMailerEvents(), static fn (MessageEvent $event): bool => !$event->isQueued()) as $event) {
            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $mails[] = $message;
        }

        return $mails;
    }

    /**
     * Everything a recipient can read: both bodies and the headers (the subject included).
     */
    private function contentOf(Email $mail): string
    {
        return implode("\n", [$mail->getHeaders()->toString(), (string) $mail->getHtmlBody(), (string) $mail->getTextBody()]);
    }
}
