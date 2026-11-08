<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Portal\Security\SubscriptionCancelToken;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Subscription;
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
 * The reminder mail is purely contractual (G9 finish, D43): the end date, the price, what stops and the cancellation
 * link, in this order and nothing else (no upsell, no advertising), so it needs no opt-out. A term free of charge (D41)
 * names no price, has nothing to cancel and offers the paid renewal. A new section must be a contract fact: change
 * this test only with the PO's word.
 */
final class SubscriptionReminderMailTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 03:15:00 Europe/Zurich');
    }

    public function test_the_paid_reminder_holds_the_contract_facts_and_nothing_else(): void
    {
        $term = $this->term(SubscriptionFactory::new());

        $mail = $this->remind();

        self::assertSame([
            'Hallo Anna',
            'Dein Tarif «Pro» für «Seehaus Meilen» läuft bis am 1. Dezember 2026.',
            'Wenn du nichts tust, schicken wir dir die Rechnung für ein weiteres Jahr Pro: CHF 199 pro Jahr. Sobald sie bezahlt ist, läuft dein Tarif ohne Unterbruch weiter.',
            'Ohne Verlängerung bleibt dein Eintrag online, im Tarif Gratis. Das ändert sich:',
            'facts: Fotos | Räume | PDFs | Videos | Anlassarten | Telefon und Website | Listen',
            'Möchtest du nicht verlängern? Dann sag es uns über den Link unten. Dein Tarif läuft trotzdem bis am 1. Dezember 2026.',
            'button: Nicht verlängern',
            'Diese Nachricht gehört zu deinem Vertrag mit uns. Fragen? Antworte einfach auf diese Mail.',
        ], $this->sections($mail));
        self::assertSame([$this->cancelUrl($term)], $this->links($mail));
        $this->assertNoOptOut($mail);
    }

    public function test_the_reminder_of_a_free_term_names_no_price_and_offers_the_paid_renewal(): void
    {
        $this->term(SubscriptionFactory::new()->freeOfCharge('Pilot'));

        $mail = $this->remind();

        self::assertSame([
            'Hallo Anna',
            'Dein kostenloser Tarif «Pro» für «Seehaus Meilen» läuft bis am 1. Dezember 2026.',
            'Möchtest du Pro danach behalten? Antworte einfach auf diese Mail, dann schicken wir dir die Rechnung für ein Jahr. Sonst musst du nichts tun.',
            'Ohne Verlängerung bleibt dein Eintrag online, im Tarif Gratis. Das ändert sich:',
            'facts: Fotos | Räume | PDFs | Videos | Anlassarten | Telefon und Website | Listen',
            'Diese Nachricht gehört zu deinem Vertrag mit uns. Fragen? Antworte einfach auf diese Mail.',
        ], $this->sections($mail));
        self::assertSame([], $this->links($mail));
        self::assertStringNotContainsString('CHF', (string) $mail->getHtmlBody());
        self::assertStringNotContainsString('CHF', (string) $mail->getTextBody());
        self::assertStringNotContainsString('Pilot', (string) $mail->getHtmlBody(), 'the staff note stays internal');
        self::assertStringNotContainsString('Pilot', (string) $mail->getTextBody());
        $this->assertNoOptOut($mail);
    }

    private function term(SubscriptionFactory $factory): Subscription
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Seehaus Meilen']);
        $this->connection->executeStatement('DELETE FROM subscriptions WHERE venue_id = ?', [$venue->id()]);
        $user = PortalUserFactory::createOne(['email' => 'anna@example.ch', 'firstName' => 'Anna']);
        PortalVenueAccessFactory::new()->active()->create(['user' => $user, 'venue' => $venue]);

        return $factory->create(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-12-02'), 'endsOn' => new \DateTimeImmutable('2026-12-01')]);
    }

    private function remind(): Email
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        (new CommandTester((new Application($kernel))->find('app:subscriptions:remind')))->execute([]);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }

    /**
     * The rows of the mail's content table, in order: a paragraph or heading by its text, a fact table by its labels,
     * a button by its label. The layout's header and footer are not part of it.
     *
     * @return list<string>
     */
    private function sections(Email $mail): array
    {
        $crawler = new Crawler((string) $mail->getHtmlBody());
        $sections = [];
        $footer = $this->translator()->trans('email.footer', [], 'shared');
        $crawler->filter('td.hl-pad')->each(static function (Crawler $cell) use (&$sections, $footer): void {
            // The layout's own rows: the logo above the content and the footer below it.
            if ($cell->filter('img')->count() > 0 || trim($cell->text()) === $footer) {
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

    /**
     * Every link in the mail but the layout's logo (it links home, which is no offer).
     *
     * @return list<string>
     */
    private function links(Email $mail): array
    {
        $crawler = new Crawler((string) $mail->getHtmlBody());

        return array_values(array_filter(
            $crawler->filter('td.hl-pad a')->each(static fn (Crawler $link): string => 0 === $link->filter('img')->count() ? (string) $link->attr('href') : ''),
            static fn (string $href): bool => '' !== $href,
        ));
    }

    private function assertNoOptOut(Email $mail): void
    {
        self::assertFalse($mail->getHeaders()->has('List-Unsubscribe'));
        foreach ([(string) $mail->getHtmlBody(), (string) $mail->getTextBody()] as $body) {
            foreach (['abmelden', 'Abmelden', 'Newsletter', 'unsubscribe', 'Angebot', 'Rabatt', 'Aktion'] as $word) {
                self::assertStringNotContainsString($word, $body);
            }
        }
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    private function cancelUrl(Subscription $term): string
    {
        $tokens = self::getContainer()->get(SubscriptionCancelToken::class);
        self::assertInstanceOf(SubscriptionCancelToken::class, $tokens);

        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);

        return $urls->generate('portal_subscription_cancel', ['token' => $tokens->forId($term->id())], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
