<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Mailer\CrmMailer;
use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\Translation\EnabledLocale;
use App\Tests\Support\Inquiries\RecordingMailer;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Address;
use Twig\Environment;

/**
 * Built by hand: `MailerInterface` is inlined in the test container, and only a hand-built mailer
 * reaches the real recipient, because .env.test sets the override. The render is the pipeline's
 * own `BodyRenderer` on the container's Twig, which `RecordingMailer` does not run.
 */
final class CrmMailerTest extends KernelTestCase
{
    private const string OVERRIDE = 'override@hochzeitslocation.test';
    private const string MAILBOX = 'hallo@hochzeitslocation.ch';
    private const string TO = 'mia@example.ch';
    private const string CC = 'tom@example.ch';

    private RecordingMailer $recording;

    protected function setUp(): void
    {
        $this->recording = new RecordingMailer();
    }

    public function test_without_an_override_the_mail_goes_to_the_real_addresses(): void
    {
        $this->mailer(null)->send(self::TO, self::CC, 'Ihre Offerte', "Liebe Mia\n\n**Gerne.**", 'Sara Beispiel');

        $mail = $this->sentMail();
        self::assertSame([self::TO], self::addresses($mail->getTo()));
        self::assertSame([self::CC], self::addresses($mail->getCc()));
        self::assertSame([self::MAILBOX], self::addresses($mail->getFrom()));
        self::assertSame('Sara Beispiel', $mail->getFrom()[0]->getName());
        self::assertSame([self::MAILBOX], self::addresses($mail->getReplyTo()));
        self::assertSame('Ihre Offerte', $mail->getSubject());
        self::assertSame("Liebe Mia\n\n**Gerne.**", $mail->getTextBody());
        self::assertSame('admin/crm/email/message.html.twig', $mail->getHtmlTemplate());
        self::assertFalse($mail->getHeaders()->has(CrmMailer::ORIGINAL_RECIPIENT_HEADER));
    }

    public function test_without_a_cc_no_cc_is_set(): void
    {
        $this->mailer(null)->send(self::TO, null, 'Ihre Offerte', 'Text', 'Sara Beispiel');

        self::assertSame([], $this->sentMail()->getCc());
    }

    public function test_the_override_takes_the_recipient_and_drops_the_cc(): void
    {
        $this->mailer(self::OVERRIDE)->send(self::TO, self::CC, 'Ihre Offerte', 'Text', 'Sara Beispiel');

        $mail = $this->sentMail();
        self::assertSame([self::OVERRIDE], self::addresses($mail->getTo()));
        self::assertSame([], $mail->getCc());
        self::assertSame(self::TO.', '.self::CC, $mail->getHeaders()->getHeaderBody(CrmMailer::ORIGINAL_RECIPIENT_HEADER));
    }

    public function test_the_override_without_a_cc_names_the_one_original(): void
    {
        $this->mailer(self::OVERRIDE)->send(self::TO, null, 'Ihre Offerte', 'Text', 'Sara Beispiel');

        self::assertSame(self::TO, $this->sentMail()->getHeaders()->getHeaderBody(CrmMailer::ORIGINAL_RECIPIENT_HEADER));
    }

    public function test_an_empty_override_means_no_override(): void
    {
        $this->mailer('')->send(self::TO, self::CC, 'Ihre Offerte', 'Text', 'Sara Beispiel');

        $mail = $this->sentMail();
        self::assertSame([self::TO], self::addresses($mail->getTo()));
        self::assertSame([self::CC], self::addresses($mail->getCc()));
        self::assertFalse($mail->getHeaders()->has(CrmMailer::ORIGINAL_RECIPIENT_HEADER));
    }

    public function test_the_body_renders_inside_the_mail_layout(): void
    {
        $crawler = new Crawler($this->renderedHtml('**Gerne** zeigen wir Ihnen den Saal.'));

        self::assertSame('Gerne', $crawler->filter('td.hl-card table td.hl-p p strong')->text());
    }

    public function test_the_text_part_stays_the_markdown_after_the_render(): void
    {
        $this->renderedHtml('**Gerne**');

        self::assertSame('**Gerne**', $this->sentMail()->getTextBody());
    }

    public function test_raw_html_in_the_markdown_is_escaped(): void
    {
        $html = $this->renderedHtml('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_twig_in_the_markdown_stays_literal(): void
    {
        $crawler = new Crawler($this->renderedHtml('Ergebnis: {{ 7*7 }}'));

        self::assertSame('Ergebnis: {{ 7*7 }}', $crawler->filter('td.hl-p p')->text());
    }

    private function renderedHtml(string $markdown): string
    {
        $this->mailer(null)->send(self::TO, null, 'Ihre Offerte', $markdown, 'Sara Beispiel');

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $mail = $this->sentMail();
        (new BodyRenderer($twig))->render($mail);

        return (string) $mail->getHtmlBody();
    }

    private function mailer(?string $override): CrmMailer
    {
        return new CrmMailer(
            $this->recording,
            new MarkdownRenderer(),
            new EnabledLocale(['de'], 'de'),
            self::MAILBOX,
            'Hochzeitslocation <'.self::MAILBOX.'>',
            $override,
        );
    }

    private function sentMail(): TemplatedEmail
    {
        self::assertCount(1, $this->recording->messages);
        $mail = $this->recording->messages[0];
        self::assertInstanceOf(TemplatedEmail::class, $mail);

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
}
