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
            ['Hallo Anna', '/konto'],
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
            static fn (PortalMailer $mailer): bool => $mailer->sendLocationAccepted('anna@example.ch', 'Anna', 'Seehaus Meilen', 1234, 'seehaus-meilen'),
            'anna@example.ch',
            'Seehaus Meilen ist online',
            // G6: the proof of publication links the public page, the settings and the way to remove the entry.
            ['Hallo Anna', '/eventlokal/seehaus-meilen', '/konto/locations/1234', 'Offline nehmen', 'Eintrag entfernen'],
        ];
        yield 'claim code' => [
            static function (PortalMailer $mailer): bool {
                $mailer->sendClaimCode('info@seehaus.example', '042917', 'Seehaus Meilen');

                return true;
            },
            'info@seehaus.example',
            'Dein Code für Seehaus Meilen',
            ['Hallo', '042917', 'Seehaus Meilen', '30 Minuten'],
        ];
        yield 'rejected' => [
            static fn (PortalMailer $mailer): bool => $mailer->sendLocationRejected('anna@example.ch', 'Anna', 'Seehaus Meilen', 1234, 'Die Fotos fehlen.'),
            'anna@example.ch',
            'Seehaus Meilen ist noch nicht online: bitte Angaben anpassen',
            ['Hallo Anna', 'Die Fotos fehlen.', '/konto/locations/1234/einrichten/pruefen'],
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

    /**
     * @return iterable<string, array{\Closure(PortalMailer, string): bool}>
     */
    public static function staffNotices(): iterable
    {
        yield 'new location' => [static fn (PortalMailer $mailer, string $email): bool => $mailer->sendLocationNotice('Seehaus Meilen', 'Anna Meier', $email)];
        yield 'access request' => [static fn (PortalMailer $mailer, string $email): bool => $mailer->sendAccessRequestNotice('Seehaus Meilen', 'Anna Meier', $email)];
    }

    /**
     * @param \Closure(PortalMailer, string): bool $send
     */
    #[DataProvider('staffNotices')]
    public function test_a_staff_notice_replies_to_the_manager(\Closure $send): void
    {
        self::assertTrue($send($this->mailer(), 'anna@example.ch'));

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame($this->contactEmail(), $mail->getTo()[0]->getAddress());
        self::assertCount(1, $mail->getReplyTo());
        self::assertSame('anna@example.ch', $mail->getReplyTo()[0]->getAddress());
        self::assertSame('Anna Meier', $mail->getReplyTo()[0]->getName());
    }

    /**
     * @param \Closure(PortalMailer, string): bool $send
     */
    #[DataProvider('staffNotices')]
    public function test_a_staff_notice_with_a_broken_manager_address_still_goes_and_replies_to_the_site(\Closure $send): void
    {
        self::assertTrue($send($this->mailer(), "anna@example.ch\r\nBcc: x@example.ch"));

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame([$this->contactEmail()], array_map(static fn ($address): string => $address->getAddress(), $mail->getReplyTo()));
        self::assertFalse($mail->getHeaders()->has('Bcc'));
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
