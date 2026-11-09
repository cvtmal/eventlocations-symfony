<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Portal\Entity\SubscriptionReminder;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Venues\Extension\TierFaqBlock;
use App\Venues\ValueObject\TierFeature;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * «Location eintragen» (G13): the matrix is the `tiers` rows, a row shows only while some tier offers its feature, and
 * the copy states only what the code does.
 */
final class TierPageTest extends WebTestCase
{
    use ResetsContentBlocks;

    private const string PATH = '/location-eintragen';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        $this->resetContentBlocks();
        parent::tearDown();
    }

    public function test_the_seeded_tiers_show_the_price_without_vat_and_free_as_free(): void
    {
        $matrix = $this->matrix();

        self::assertSame(['Gratis', 'Pro'], $matrix->filter('thead th')->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame(['Gratis, ohne Ablaufdatum', 'CHF 199 pro Jahr Laufzeit ein Jahr, auf Rechnung'], $this->cells($matrix, 'Preis'));
        self::assertStringNotContainsString('MwSt.', $this->main()->text());
    }

    public function test_the_vat_line_follows_the_tier_row(): void
    {
        $this->sql("UPDATE tiers SET vat_line = TRUE WHERE code = 'pro'");

        self::assertSame('CHF 199 pro Jahr zzgl. MwSt. Laufzeit ein Jahr, auf Rechnung', $this->cells($this->matrix(), 'Preis')[1]);
    }

    public function test_the_price_follows_the_tier_row(): void
    {
        $this->sql("UPDATE tiers SET price_rappen = 24950 WHERE code = 'pro'");

        self::assertSame('CHF 249.50 pro Jahr Laufzeit ein Jahr, auf Rechnung', $this->cells($this->matrix(), 'Preis')[1]);
    }

    public function test_a_limit_follows_the_tier_row(): void
    {
        $this->sql("UPDATE tiers SET photos_max = 12 WHERE code = 'pro'");

        self::assertSame(['bis zu 6', 'bis zu 12'], $this->cells($this->matrix(), 'Fotos'));
    }

    public function test_each_cell_reads_its_own_tier(): void
    {
        $matrix = $this->matrix();

        self::assertSame(['– nicht enthalten', 'inklusive'], $this->cells($matrix, 'Telefonnummer und Website im Eintrag'));
        self::assertSame(['– nicht enthalten', 'inklusive'], $this->cells($matrix, 'Top-Eintrag: weiter oben in den Suchergebnissen'));
        self::assertSame(['1', 'bis zu 20'], $this->cells($matrix, 'Räume'));
        self::assertSame(['1', 'bis zu 5'], $this->cells($matrix, 'Videos'));
        self::assertSame(['bis zu 3', 'alle passenden'], $this->cells($matrix, 'Anlassarten'));
        self::assertSame(['– nicht enthalten', 'inklusive'], $this->cells($matrix, 'Kurzfristig frei'));
        self::assertSame(['– nicht enthalten', 'inklusive'], $this->cells($matrix, 'Ausführlicher Monatsbericht: Klicks, Sichtbarkeit in der Suche, drei Monate im Vergleich'));
    }

    /**
     * The done criterion: the page never lists a feature no tier offers. Each case switches features off in the
     * `tiers` rows; every `TierFeature` row label must then be on the page exactly when some row still offers it.
     *
     * @param list<string> $updates
     */
    #[DataProvider('tierStates')]
    public function test_the_page_lists_a_feature_only_while_some_tier_offers_it(array $updates): void
    {
        foreach ($updates as $update) {
            $this->sql($update);
        }

        $labels = $this->matrix()->filter('tbody th[scope=row]')->each(static fn (Crawler $th): string => $th->text());

        foreach (TierFeature::cases() as $feature) {
            $label = $this->trans('tier_page.row.'.$feature->value);
            self::assertSame($this->offered($feature), \in_array($label, $labels, true), $feature->value);
        }
        self::assertContains('Preis', $labels, 'the price row always shows');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function tierStates(): iterable
    {
        yield 'as seeded' => [[]];
        yield 'contact off everywhere' => [['UPDATE tiers SET contact_visible = FALSE']];
        yield 'no boost' => [['UPDATE tiers SET rank_weight = 0']];
        yield 'no documents' => [['UPDATE tiers SET documents_max = 0']];
        yield 'no videos' => [['UPDATE tiers SET videos_max = 0']];
        yield 'no last-minute offers' => [['UPDATE tiers SET last_minute_allowed = FALSE']];
        yield 'no detailed report' => [['UPDATE tiers SET detailed_report = FALSE']];
        yield 'detailed report on Gratis too' => [["UPDATE tiers SET detailed_report = TRUE WHERE code = 'free'"]];
        yield 'every flag off' => [[
            'UPDATE tiers SET contact_visible = FALSE, last_minute_allowed = FALSE, detailed_report = FALSE, rank_weight = 0, photos_max = 0, rooms_max = 0, videos_max = 0, documents_max = 0',
        ]];
    }

    public function test_with_every_flag_off_only_price_and_event_types_remain_and_nothing_says_top_eintrag(): void
    {
        $this->sql('UPDATE tiers SET contact_visible = FALSE, last_minute_allowed = FALSE, detailed_report = FALSE, rank_weight = 0, photos_max = 0, rooms_max = 0, videos_max = 0, documents_max = 0');

        $page = $this->page();
        $main = $page->filter('main');

        $labels = $main->filter('table.tier-matrix tbody th[scope=row]')->each(static fn (Crawler $th): string => $th->text());
        self::assertSame(['Preis', 'Anlassarten'], $labels);
        self::assertNotContains('Videos', $labels);
        self::assertNotContains('Kurzfristig frei', $labels);
        self::assertCount(0, $main->filter('#top-eintrag'));
        self::assertStringNotContainsString('Top-Eintrag', $main->text());
        self::assertStringNotContainsString('weiter oben', $main->text());
        self::assertSame('Der Eintrag ist gratis. Mit Pro zeigst du mehr. Anfragen kommen in jedem Fall direkt per E-Mail zu dir, ohne Provision.', $main->filter('.tier-page__intro')->text());
        self::assertStringNotContainsString('Top-Eintrag', (string) $page->filter('meta[name="description"]')->attr('content'));
    }

    public function test_with_a_promoted_tier_the_intro_and_the_meta_description_name_the_ranking(): void
    {
        $page = $this->page();

        self::assertStringContainsString('und stehst in den Suchergebnissen weiter oben', $page->filter('main .tier-page__intro')->text());
        self::assertStringContainsString('und einen Top-Eintrag', (string) $page->filter('meta[name="description"]')->attr('content'));
    }

    public function test_the_top_eintrag_section_names_the_promoted_tiers(): void
    {
        $section = $this->main()->filter('#top-eintrag');

        self::assertCount(1, $section);
        self::assertStringStartsWith('Einträge mit Pro sind bezahlt, tragen das Label «Top-Eintrag»', $section->filter('p')->text());
        self::assertSame('#top-eintrag', $this->matrix()->filter('tbody th a')->attr('href'));
    }

    public function test_the_page_is_cached_and_sets_no_cookie(): void
    {
        $crawler = $this->page();
        $response = $this->client->getResponse();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        foreach (['public', 's-maxage=86400', 'stale-while-revalidate=86400', 'stale-if-error=86400'] as $directive) {
            self::assertStringContainsString($directive, $cacheControl);
        }
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame('http://localhost/location-eintragen', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('Location eintragen: gratis oder mit Pro | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('h1'));
    }

    public function test_the_faq_default_shows_the_six_questions(): void
    {
        $questions = $this->main()->filter('.tier-page__faq h3')->each(static fn (Crawler $h3): string => $h3->text());

        self::assertSame(array_map(fn (array $entry): string => $this->trans($entry[0]), TierFaqBlock::DEFAULT_ENTRIES), $questions);
        self::assertCount(6, $questions);
    }

    public function test_a_stored_faq_replaces_the_default(): void
    {
        $this->connection->insert('content_blocks', ['key' => TierFaqBlock::KEY, 'locale' => 'de', 'body_markdown' => "### Eigene Frage\n\nEigene Antwort.", 'updated_at' => '2026-11-08 08:00:00+00']);
        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();

        $faq = $this->main()->filter('.tier-page__faq');

        self::assertStringContainsString('Eigene Antwort.', $faq->filter('.tier-page__faq-staff')->text());
        self::assertStringNotContainsString($this->trans('tier_page.faq.cost.question'), $faq->text());
    }

    public function test_the_ctas_lead_to_registration_and_the_account(): void
    {
        $cta = $this->main()->filter('.tier-page__cta');

        self::assertSame('/konto/registrieren', $cta->filter('a.btn')->attr('href'));
        self::assertSame('Location gratis eintragen oder übernehmen', $cta->filter('a.btn')->text());
        self::assertSame('/konto', $cta->filter('a.tier-page__login')->attr('href'));
    }

    /**
     * W7 states the reminder days; `SubscriptionReminder::DAYS` is what the cron sends. A change to either is noticed.
     */
    public function test_the_cancellation_answer_names_the_reminder_days_the_code_sends(): void
    {
        self::assertSame([30, 7], SubscriptionReminder::DAYS);
        self::assertStringContainsString(
            \sprintf('%d und %d Tage vor dem Ende erinnern wir dich per E-Mail', ...SubscriptionReminder::DAYS),
            $this->main()->filter('.tier-page__faq')->text(),
        );
    }

    /**
     * A report hides the photos at once only until the day's hold limit is reached, hence «in der Regel».
     */
    public function test_the_removal_answer_does_not_promise_an_unconditional_hide(): void
    {
        self::assertStringContainsString('blenden wir nach deiner Meldung in der Regel sofort aus', $this->main()->filter('.tier-page__faq')->text());
    }

    public function test_the_page_names_no_competitor(): void
    {
        self::assertStringNotContainsStringIgnoringCase('eventlokale.ch', $this->main()->text());
        self::assertStringNotContainsStringIgnoringCase('eventlokale.ch', $this->main()->html());
    }

    private function offered(TierFeature $feature): bool
    {
        $column = match ($feature) {
            TierFeature::Promoted => 'rank_weight > 0',
            TierFeature::Photos => 'photos_max > 0',
            TierFeature::Videos => 'videos_max > 0',
            TierFeature::Rooms => 'rooms_max > 0',
            TierFeature::Documents => 'documents_max > 0',
            TierFeature::Contact => 'contact_visible',
            TierFeature::EventTypes => '(event_types_max IS NULL OR event_types_max > 0)',
            TierFeature::LastMinute => 'last_minute_allowed',
            TierFeature::DetailedReport => 'detailed_report',
        };

        return (bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM tiers WHERE '.$column.')');
    }

    /**
     * @return list<string>
     */
    private function cells(Crawler $matrix, string $rowLabel): array
    {
        $row = $matrix->filter('tbody tr')->reduce(static fn (Crawler $tr): bool => $tr->filter('th')->text() === $rowLabel);
        self::assertCount(1, $row, $rowLabel);

        return $row->filter('td')->each(static fn (Crawler $td): string => $td->text());
    }

    private function matrix(): Crawler
    {
        $matrix = $this->main()->filter('table.tier-matrix');
        self::assertCount(1, $matrix);

        return $matrix;
    }

    private function main(): Crawler
    {
        return $this->page()->filter('main');
    }

    private function page(): Crawler
    {
        $crawler = $this->client->request('GET', self::PATH);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function sql(string $statement): void
    {
        $this->connection->executeStatement($statement);
    }

    private function trans(string $key): string
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator->trans($key, ['email' => 'x'], 'venues');
    }
}
