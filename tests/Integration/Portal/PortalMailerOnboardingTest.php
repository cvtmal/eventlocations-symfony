<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Portal\Mailer\PortalMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * The four mails of the review (#45, POR-12, POR-13), and staff's notice of an access request (`portal-b4-followups`).
 * The `rejected` row needs Phase 3's route, `portal_location_setup_step`, and both notices need #43's `admin_portal_review`.
 */
final class PortalMailerOnboardingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{\Closure(PortalMailer): bool, string, string, list<string>}>
     */
    public static function mails(): iterable
    {
        yield 'submitted' => [
            static fn (PortalMailer $mailer): bool => $mailer->sendLocationSubmitted('anna@example.ch', 'Anna', 'Seehaus Meilen'),
            'anna@example.ch',
            'Wir prüfen Seehaus Meilen',
            ['Guten Tag Anna', '/konto'],
        ];
        yield 'notice' => [
            static fn (PortalMailer $mailer): bool => $mailer->sendLocationNotice('Seehaus Meilen', 'Anna Meier', 'anna@example.ch'),
            'contact',
            'Neue Location zur Prüfung: Seehaus Meilen',
            ['Anna Meier', 'anna@example.ch', 'tab=neu'],
        ];
        yield 'access request notice' => [
            static fn (PortalMailer $mailer): bool => $mailer->sendAccessRequestNotice('Seehaus Meilen', 'Anna Meier', 'anna@example.ch'),
            'contact',
            'Neue Zugriffsanfrage: Seehaus Meilen',
            ['Für Seehaus Meilen liegt eine neue Zugriffsanfrage vor.', 'Anna Meier', 'anna@example.ch', '/admin/pruefung?tab=requests'],
        ];
        yield 'accepted' => [
            static fn (PortalMailer $mailer): bool => $mailer->sendLocationAccepted('anna@example.ch', 'Anna', 'Seehaus Meilen', 1234),
            'anna@example.ch',
            'Seehaus Meilen ist online',
            ['Guten Tag Anna', '/konto/locations/1234'],
        ];
        yield 'rejected' => [
            static fn (PortalMailer $mailer): bool => $mailer->sendLocationRejected('anna@example.ch', 'Anna', 'Seehaus Meilen', 1234, 'Die Fotos fehlen.'),
            'anna@example.ch',
            'Deine Location Seehaus Meilen',
            ['Guten Tag Anna', 'Die Fotos fehlen.', '/konto/locations/1234/einrichten/pruefen'],
        ];
    }

    /**
     * @param \Closure(PortalMailer): bool $send
     * @param list<string>                 $body
     */
    #[DataProvider('mails')]
    public function test_a_mail_goes_out_with_its_subject_and_link(\Closure $send, string $to, string $subject, array $body): void
    {
        self::assertTrue($send($this->mailer()));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame($subject, $mail->getSubject());
        self::assertSame('contact' === $to ? $this->contactEmail() : $to, $mail->getTo()[0]->getAddress());
        $html = (string) $mail->getHtmlBody();
        foreach ($body as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function test_an_invalid_address_gives_false(): void
    {
        self::assertFalse($this->mailer()->sendLocationSubmitted('keine adresse', 'Anna', 'Seehaus Meilen'));

        self::assertEmailCount(0);
    }

    private function mailer(): PortalMailer
    {
        $mailer = self::getContainer()->get(PortalMailer::class);
        self::assertInstanceOf(PortalMailer::class, $mailer);

        return $mailer;
    }

    private function contactEmail(): string
    {
        $email = self::getContainer()->getParameter('site.contact_email');
        self::assertIsString($email);

        return $email;
    }
}
