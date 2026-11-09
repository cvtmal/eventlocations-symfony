<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\InquiryFactory;
use App\Factory\InquiryFeedbackFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\RecordedFunnelEventFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ValueObject\FeedbackOutcome;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\QualificationReason;
use App\Portal\Security\ReportOptOutToken;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The monthly report mail (G10, D52), section by section: the subject from intl, four basic figures (G7's qualified
 * row included) plus G16's «Rückmeldungen» only while there are some, on Free exactly one «Pro anfragen» line and no
 * other advertising, on Pro the detailed rows and the trend, an honest mail without data, the opt-out link in the body
 * and in the RFC 8058 headers. "Anfragen", never "Buchungen".
 */
final class OwnerReportMailTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-01 03:15:00 Europe/Zurich');
        foreach (['stats_ingest_files', 'funnel_events', 'stats_daily'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->insert('stats_ingest_files', ['id' => EntityId::generate(), 'file_name' => 'test-mail', 'events' => 1, 'created_at' => '2026-11-01T03:16:00+01:00']);
    }

    public function test_the_free_report_has_four_figures_and_one_pro_line(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->traffic($venue, october: 40, september: 50);

        $mail = $this->send();

        self::assertSame('Dein Monatsbericht für Alpstube Seeblick: Oktober 2026', $mail->getSubject());
        self::assertSame([
            'Hallo Anna',
            'Hier sind die Zahlen von «Alpstube Seeblick» für Oktober 2026, verglichen mit dem Vormonat.',
            'facts: Besuche | Interaktionen | Zugestellte Anfragen | Qualifizierte Anfragen',
            'Besuche zählen jede Person einmal pro Tag. Interaktionen sind Klicks auf deine Kontaktangaben und Einträge auf der Merkliste. Qualifiziert sind zugestellte Anfragen, deren Anlass und Gästezahl zu deinem Eintrag passen und deren Termin höchstens zwei Jahre entfernt ist. Doppelte Anfragen, Spam und Tests zählen nicht. Stand: 1. November 2026',
            'Hinterlege eine E-Mail-Adresse für Anfragen, damit Interessierte dich direkt erreichen.',
            'button: E-Mail-Adresse ergänzen',
            'Dein Tarif: Gratis-Eintrag, ohne Ablaufdatum.',
            'link: Mehr Fotos, Räume und Zahlen gibt es mit Pro. | Pro anfragen',
            'button: Eintrag bearbeiten',
            'link: Du möchtest den Monatsbericht für «Alpstube Seeblick» nicht mehr? | Abbestellen',
        ], $this->sections($mail));

        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Besuche: 40 (Vormonat: 50, −10)', $text);
        self::assertStringContainsString('Interaktionen: 5 (Vormonat: 1, +4)', $text);
        self::assertStringContainsString('Zugestellte Anfragen: 2 (Vormonat: 2, ±0)', $text);
        self::assertStringContainsString('Qualifizierte Anfragen: 0 (Vormonat: 0, ±0)', $text);
        foreach ([(string) $mail->getHtmlBody(), $text] as $body) {
            self::assertSame(1, substr_count($body, 'Pro anfragen'), 'exactly one «Pro anfragen» line (D52)');
            self::assertStringContainsString('Gratis-Eintrag, ohne Ablaufdatum', $body);
            self::assertStringNotContainsString('In Suchergebnissen gezeigt', $body);
            self::assertStringNotContainsString('Rückmeldungen', $body, 'no answers in either month: no row, no note');
        }
        $this->assertHonest($mail, $venue);
    }

    public function test_the_pro_report_adds_the_detailed_figures_and_the_trend(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->traffic($venue, october: 40, september: 50);

        $mail = $this->send();
        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();

        $sections = $this->sections($mail);
        self::assertSame('facts: Besuche | Interaktionen | Zugestellte Anfragen | Qualifizierte Anfragen', $sections[2]);
        self::assertContains('Mehr Zahlen aus dem Oktober 2026:', $sections);
        self::assertContains('facts: Klicks auf die Website | Klicks auf die E-Mail-Adresse | Klicks auf die Telefonnummer | Klicks auf die Karte | Klicks auf Instagram | In Suchergebnissen gezeigt | Auf die Merkliste gesetzt | Abgeschickte Anfragen', $sections);
        self::assertContains('Die letzten drei Monate:', $sections);
        self::assertContains('facts: Oktober 2026 | September 2026 | August 2026', $sections);
        self::assertStringContainsString("In Suchergebnissen gezeigt: 1'900 (Vormonat: 700, +1'200)", $text, 'Swiss grouping');
        self::assertStringContainsString('Oktober 2026: Besuche 40, Interaktionen 5, Zugestellte Anfragen 2', $text);
        self::assertStringContainsString('Bezahlt bis', $html);
        foreach ([$html, $text] as $body) {
            self::assertSame(0, substr_count($body, 'Pro anfragen'), 'Pro gets no advertising');
        }
        $this->assertHonest($mail, $venue);
    }

    /**
     * G7 and G16 through Stats: qualified inquiries by the month of their venue mail (an unqualified or spam one is not
     * counted), and «Rückmeldungen» by the month of the answer, explained once in the note. Answers are never called
     * bookings.
     */
    public function test_the_qualified_and_the_feedback_rows(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->traffic($venue, october: 40, september: 50);
        $this->inquiry($venue, '2026-10-03 12:00', answeredAt: '2026-10-12 08:00');
        $this->inquiry($venue, '2026-10-20 12:00');
        $this->inquiry($venue, '2026-09-20 12:00', answeredAt: '2026-10-01 09:00');
        $this->inquiry($venue, '2026-10-21 12:00', reason: QualificationReason::Duplicate);
        $this->inquiry($venue, '2026-10-22 12:00', mark: InquiryOverride::Spam, answeredAt: '2026-10-25 09:00');

        $mail = $this->send();
        $text = (string) $mail->getTextBody();

        self::assertSame('facts: Besuche | Interaktionen | Zugestellte Anfragen | Qualifizierte Anfragen | Rückmeldungen von Veranstaltern', $this->sections($mail)[2]);
        self::assertStringContainsString('Qualifizierte Anfragen: 2 (Vormonat: 1, +1)', $text);
        self::assertStringContainsString('Rückmeldungen von Veranstaltern: 2 (Vormonat: 0, +2)', $text);
        self::assertStringContainsString('Spam und Tests zählen nicht. Rückmeldungen zählen, wie viele Veranstalter nach ihrer Anfrage unsere kurze Umfrage beantwortet haben. Die Antworten selbst geben wir nicht weiter. Stand:', $text);
        $this->assertHonest($mail, $venue);
    }

    public function test_the_feedback_row_stays_while_the_month_before_had_answers(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->traffic($venue, october: 40, september: 50);
        $this->inquiry($venue, '2026-09-03 12:00', answeredAt: '2026-09-12 08:00');

        $text = (string) $this->send()->getTextBody();

        self::assertStringContainsString('Rückmeldungen von Veranstaltern: 0 (Vormonat: 1, −1)', $text);
        self::assertStringContainsString('Rückmeldungen zählen, wie viele Veranstalter', $text, 'the row comes with its note');
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function terms(): iterable
    {
        yield 'free of charge' => [false, 'Dein Tarif Pro: Kostenlos bis 31. März 2027.'];
        yield 'cancelled' => [true, 'Dein Tarif Pro: Gekündigt, läuft bis 31. März 2027.'];
    }

    #[DataProvider('terms')]
    public function test_the_plan_line_names_the_term(bool $cancelled, string $line): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->connection->executeStatement('DELETE FROM subscriptions WHERE venue_id = ?', [$venue->id()]);
        ($cancelled ? SubscriptionFactory::new()->cancelled() : SubscriptionFactory::new()->freeOfCharge())->create(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-04-01'), 'endsOn' => new \DateTimeImmutable('2027-03-31')]);

        $sections = $this->sections($this->send());

        self::assertContains($line, $sections);
        self::assertNotContains('Dein Tarif Pro: Bezahlt bis 31. März 2027.', $sections);
    }

    public function test_a_report_without_data_says_so_and_puts_the_suggestion_first(): void
    {
        $this->venue(KnownTiers::FREE);

        $sections = $this->sections($this->send());

        self::assertSame([
            'Hallo Anna',
            'Im Oktober 2026 wurde «Alpstube Seeblick» nicht aufgerufen, und es kamen keine Anfragen. Ein vollständiger Eintrag wird leichter gefunden.',
            'Hinterlege eine E-Mail-Adresse für Anfragen, damit Interessierte dich direkt erreichen.',
            'button: E-Mail-Adresse ergänzen',
            'facts: Besuche | Interaktionen | Zugestellte Anfragen | Qualifizierte Anfragen',
            'Besuche zählen jede Person einmal pro Tag. Interaktionen sind Klicks auf deine Kontaktangaben und Einträge auf der Merkliste. Qualifiziert sind zugestellte Anfragen, deren Anlass und Gästezahl zu deinem Eintrag passen und deren Termin höchstens zwei Jahre entfernt ist. Doppelte Anfragen, Spam und Tests zählen nicht. Stand: 1. November 2026',
            'Dein Tarif: Gratis-Eintrag, ohne Ablaufdatum.',
            'link: Mehr Fotos, Räume und Zahlen gibt es mit Pro. | Pro anfragen',
            'button: Eintrag bearbeiten',
            'link: Du möchtest den Monatsbericht für «Alpstube Seeblick» nicht mehr? | Abbestellen',
        ], $sections);
    }

    public function test_the_links_and_the_one_click_headers(): void
    {
        $venue = $this->venue(KnownTiers::FREE);

        $mail = $this->send();

        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        $optOut = $urls->generate('portal_report_opt_out', ['token' => $this->token($venue)], UrlGeneratorInterface::ABSOLUTE_URL);
        $edit = $urls->generate('portal_location', ['number' => $venue->number()], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertStringEndsWith('/konto/locations/'.$venue->number(), $edit);
        self::assertSame('<'.$optOut.'>', $mail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $mail->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertMatchesRegularExpression('#/konto/bericht-abbestellen/[A-Za-z0-9_-]{44}$#', $optOut);
        $links = (new Crawler((string) $mail->getHtmlBody()))->filter('a.hl-btn')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertSame([
            $edit.'#kontakt',
            $edit.'#location-plan',
            $edit,
            $optOut,
        ], $links);
        self::assertStringContainsString('Abbestellen: '.$optOut, (string) $mail->getTextBody());
        // F16: one «Pro anfragen» label, after a full stop, not «Pro: Pro anfragen:».
        self::assertStringContainsString('Mehr Fotos, Räume und Zahlen gibt es mit Pro. Pro anfragen: '.$edit.'#location-plan', (string) $mail->getTextBody());
        foreach ([(string) $mail->getHtmlBody(), (string) $mail->getTextBody()] as $body) {
            self::assertStringNotContainsString('Pro: Pro', $body);
        }
        self::assertSame('Pro anfragen', (new Crawler((string) $mail->getHtmlBody()))->filter('a.hl-btn[href$="#location-plan"]')->text());
    }

    private function assertHonest(Email $mail, Venue $venue): void
    {
        $text = (string) $mail->getTextBody();
        self::assertSame(strip_tags($text), $text, 'the text part has no tags');
        foreach ([(string) $mail->getHtmlBody(), $text] as $body) {
            foreach (['Buchung', 'gebucht', '%', 'ß'] as $word) {
                self::assertStringNotContainsString($word, strip_tags($body), $word);
            }
            foreach ([$venue->phone(), $venue->website()] as $contact) {
                self::assertNotNull($contact);
                self::assertStringNotContainsString($contact, $body, 'the report names no phone or website (§4a.2)');
            }
        }
    }

    private function venue(string $tier): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->withContact()->create(['name' => 'Alpstube Seeblick', 'tier' => $tier]);
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL WHERE id = ?', [$venue->id()]);
        $user = PortalUserFactory::createOne(['email' => 'anna@example.ch', 'firstName' => 'Anna']);
        PortalVenueAccessFactory::new()->active()->create(['user' => $user, 'venue' => $venue]);

        return $venue;
    }

    private function traffic(Venue $venue, int $october, int $september): void
    {
        foreach (['2026-10-12' => $october, '2026-09-12' => $september, '2026-08-12' => 7] as $day => $visitors) {
            $this->stat($venue, $day, 'detail_view', $visitors);
        }
        $this->stat($venue, '2026-10-12', 'click_phone', 3);
        $this->stat($venue, '2026-10-12', 'merkliste_add', 2);
        $this->stat($venue, '2026-09-12', 'click_website', 1);
        $this->stat($venue, '2026-10-12', 'impression', 1900);
        $this->stat($venue, '2026-09-12', 'impression', 700);
        $this->stat($venue, '2026-10-12', 'inquiry', 2);
        foreach (['2026-10-03 12:00', '2026-10-20 12:00', '2026-09-03 12:00', '2026-09-20 12:00'] as $at) {
            RecordedFunnelEventFactory::createOne(['event' => FunnelEvent::InquiryDelivered, 'venueId' => $venue->id(), 'occurredAt' => new \DateTimeImmutable($at.' Europe/Zurich')]);
        }
    }

    /**
     * A delivered inquiry, qualified unless `reason` says otherwise, with a feedback answer when `answeredAt` is set.
     */
    private function inquiry(Venue $venue, string $sentAt, ?QualificationReason $reason = null, ?InquiryOverride $mark = null, ?string $answeredAt = null): void
    {
        $at = new \DateTimeImmutable($sentAt.' Europe/Zurich');
        $inquiry = InquiryFactory::new()
            ->afterInstantiate(static function (Inquiry $inquiry) use ($at, $reason, $mark): void {
                $inquiry->recordVenueMailSent('team@example.ch', $at);
                $inquiry->recordQualification($reason, $at);
                $inquiry->mark($mark, $at);
            })
            ->create(['venue' => $venue, 'createdAt' => $at]);
        if (null !== $answeredAt) {
            InquiryFeedbackFactory::new()
                ->answered(true, FeedbackOutcome::Open, new \DateTimeImmutable($answeredAt.' Europe/Zurich'))
                ->create(['inquiry' => $inquiry, 'at' => $at->modify('+7 days')]);
        }
    }

    private function stat(Venue $venue, string $day, string $metric, int $count): void
    {
        $this->connection->insert('stats_daily', ['day' => $day, 'venue_number' => $venue->number(), 'metric' => $metric, 'events' => $count, 'visitors' => $count]);
    }

    private function send(): Email
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        (new CommandTester((new Application($kernel))->find('app:portal:owner-reports')))->execute([]);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }

    /**
     * The rows of the mail's content table, in order: a paragraph by its text, a fact table by its labels, a button by
     * its label, a line ending in a link as `link: text | label`. The layout's header and footer are not part of it.
     *
     * @return list<string>
     */
    private function sections(Email $mail): array
    {
        $crawler = new Crawler((string) $mail->getHtmlBody());
        $sections = [];
        $footer = $this->translator()->trans('email.footer', [], 'shared');
        $crawler->filter('td.hl-pad')->each(static function (Crawler $cell) use (&$sections, $footer): void {
            if ($cell->filter('img')->count() > 0 || trim($cell->text()) === $footer) {
                return;
            }
            if ($cell->filter('a.hl-link')->count() > 0) {
                $label = trim($cell->filter('a')->text());
                $sections[] = 'link: '.trim(substr(trim($cell->text()), 0, -\strlen($label))).' | '.$label;

                return;
            }
            if ($cell->filter('a.hl-btn')->count() > 0) {
                $sections[] = 'button: '.trim($cell->filter('a')->text());

                return;
            }
            $rows = $cell->filter('table td.hl-p');
            if ($rows->count() > 0) {
                $sections[] = 'facts: '.implode(' | ', $rows->each(static fn (Crawler $row): string => trim(explode(':', $row->text())[0])));

                return;
            }
            $sections[] = trim(preg_replace('/\s+/u', ' ', $cell->text()) ?? '');
        });

        return $sections;
    }

    private function token(Venue $venue): string
    {
        $tokens = self::getContainer()->get(ReportOptOutToken::class);
        self::assertInstanceOf(ReportOptOutToken::class, $tokens);

        return $tokens->forVenueId($venue->id());
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
