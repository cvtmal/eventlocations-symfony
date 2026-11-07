<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Command\ReviseTier;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The screen "Tarife" (F12): the list of the tiers with their figures, the editor with its validation, the save
 * that is a 303 with an audit row and a cache purge, the save that changes nothing, a rename to a name another tier
 * has (a field error before the command, and from the key when the name is taken in between), the 404 for a code no
 * tier has, and the login every route needs.
 */
final class TierScreensTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const array HEADERS = ['Name', 'Code', 'Preis pro Jahr (CHF)', 'Rang', 'Fotos', 'Räume', 'Videos', 'PDFs', 'Kontaktdaten sichtbar', 'Last-Minute', 'Detailbericht', 'Anlassarten', 'Einträge', 'Aktionen'];

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/tarife');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', '/admin/tarife/pro');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('POST', '/admin/tarife/pro');
        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_list_shows_every_tier_best_first_with_its_figures(): void
    {
        $this->logInAs(AdminRole::Editor);
        VenueFactory::new()->withoutLocation()->many(2)->create(['tier' => KnownTiers::PRO]);
        VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::FREE]);

        $crawler = $this->client->request('GET', '/admin/tarife');

        self::assertResponseIsSuccessful();
        self::assertSame('Tarife', $crawler->filter('.sidebar__item--active')->text());
        self::assertSame(self::HEADERS, $crawler->filter('#tier-list thead th')->each(static fn (Crawler $header): string => $header->text()));
        self::assertSame(
            [
                ['Pro', 'pro', '199', '10', '60', '20', '5', '5', 'Ja', 'Ja', 'Ja', 'alle', (string) $this->venuesOn('pro'), 'Bearbeiten'],
                ['Free', 'free', '0', '0', '6', '1', '1', '1', 'Nein', 'Nein', 'Nein', '3', (string) $this->venuesOn('free'), 'Bearbeiten'],
            ],
            $crawler->filter('#tier-list tbody tr')->each(fn (Crawler $row): array => $this->cells($row)),
        );
        self::assertGreaterThanOrEqual(2, $this->venuesOn('pro'));
        self::assertSame('/admin/tarife/pro', $crawler->filter('#tier-list tbody tr')->eq(0)->filter('td a')->first()->attr('href'));
        self::assertSame('/admin/tarife/free', $crawler->filter('#tier-list tbody tr')->eq(1)->filter('td.table__actions a')->attr('href'));
        self::assertSame('Top-Eintrag', $crawler->filter('#tier-list tbody tr')->eq(0)->filter('.pill')->text());
        self::assertCount(0, $crawler->filter('#tier-list tbody tr')->eq(1)->filter('.pill'));
    }

    public function test_a_price_with_rappen_shows_two_decimals(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->connection->executeStatement("UPDATE tiers SET price_rappen = 19950 WHERE code = 'pro'");

        $crawler = $this->client->request('GET', '/admin/tarife');

        self::assertSame('199.50', $this->cells($crawler->filter('#tier-list tbody tr')->eq(0))[2]);
    }

    public function test_the_sidebar_lists_the_tiers_last_in_the_cms_section(): void
    {
        $this->logInAs(AdminRole::Editor);

        $labels = $this->client->request('GET', '/admin')->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text());

        $textBlocks = array_search('Textbausteine', $labels, true);
        self::assertIsInt($textBlocks);
        self::assertSame($textBlocks + 1, array_search('Tarife', $labels, true));
    }

    public function test_the_editor_shows_the_values_and_the_code_but_not_as_a_field(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/tarife/pro');

        self::assertResponseIsSuccessful();
        self::assertSame('Tarife', $crawler->filter('.sidebar__item--active')->text());
        self::assertSame('Pro', $crawler->filter('.record-header h1')->text());
        self::assertStringContainsString('Code: pro', $crawler->filter('.record-header__subtitle')->text());
        self::assertSame(
            ['tier[name]' => 'Pro', 'tier[priceRappen]' => '199', 'tier[rankWeight]' => '10', 'tier[photosMax]' => '60', 'tier[roomsMax]' => '20', 'tier[videosMax]' => '5', 'tier[documentsMax]' => '5', 'tier[eventTypesMax]' => ''],
            array_intersect_key($this->form($crawler)->getValues(), array_flip(['tier[name]', 'tier[priceRappen]', 'tier[rankWeight]', 'tier[photosMax]', 'tier[roomsMax]', 'tier[videosMax]', 'tier[documentsMax]', 'tier[eventTypesMax]'])),
        );
        self::assertCount(3, $crawler->filter('input[type="checkbox"][checked]'));
        self::assertSame('CHF', $crawler->filter('.field__addon-text')->text());
        self::assertSelectorNotExists('input[name="tier[code]"]');

        $crawler = $this->client->request('GET', '/admin/tarife/free');

        self::assertSame('3', $this->form($crawler)->getValues()['tier[eventTypesMax]']);
        self::assertCount(0, $crawler->filter('input[type="checkbox"][checked]'));
    }

    public function test_a_save_stores_the_values_flashes_and_goes_back_to_the_list(): void
    {
        $this->logInAs(AdminRole::Editor);
        $purges = $this->purger()->listings;
        $crawler = $this->client->request('GET', '/admin/tarife/pro');
        $form = $this->form($crawler);
        $contactVisible = $form['tier[contactVisible]'];
        \assert($contactVisible instanceof ChoiceFormField);
        $contactVisible->untick();

        $this->client->submit($form, [
            'tier[name]' => '  Pro Plus ',
            'tier[priceRappen]' => '249.50',
            'tier[photosMax]' => '80',
            'tier[eventTypesMax]' => '8',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/tarife');
        $crawler = $this->client->followRedirect();
        self::assertSame('«Pro Plus» gespeichert.', $crawler->filter('.toast')->text());
        self::assertSame('Pro Plus', $crawler->filter('#tier-list tbody tr')->eq(0)->filter('td a')->first()->text());
        self::assertEquals(
            ['name' => 'Pro Plus', 'price_rappen' => 24950, 'rank_weight' => 10, 'photos_max' => 80, 'rooms_max' => 20, 'videos_max' => 5, 'documents_max' => 5, 'contact_visible' => false, 'last_minute_allowed' => true, 'detailed_report' => true, 'event_types_max' => 8],
            $this->connection->fetchAssociative("SELECT name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max FROM tiers WHERE code = 'pro'"),
        );
        self::assertSame($purges + 1, $this->purger()->listings);
        self::assertSame(
            [['command' => ReviseTier::class, 'subject_id' => 'pro', 'changes' => '{"changed": ["name", "priceRappen", "photosMax", "contactVisible", "eventTypesMax"]}', 'actor_type' => 'admin_user']],
            $this->connection->fetchAllAssociative("SELECT command, subject_id, changes::text AS changes, actor_type FROM admin_audit_log WHERE subject_type = 'tier'"),
        );
    }

    public function test_the_editor_names_who_saved_last(): void
    {
        $this->logInAs(AdminRole::Editor, 'Beat Meier');
        $crawler = $this->client->request('GET', '/admin/tarife/pro');
        self::assertStringNotContainsString('zuletzt bearbeitet', $crawler->filter('.record-header__subtitle')->text());

        $this->client->submit($this->form($crawler), ['tier[rankWeight]' => '12']);
        $crawler = $this->client->request('GET', '/admin/tarife/pro');

        self::assertStringContainsString('zuletzt bearbeitet von Beat Meier', $crawler->filter('.record-header__subtitle')->text());
    }

    public function test_an_admin_may_save_too(): void
    {
        $this->logInAs(AdminRole::Admin);
        $crawler = $this->client->request('GET', '/admin/tarife/free');

        $this->client->submit($this->form($crawler), ['tier[roomsMax]' => '2']);

        self::assertResponseRedirects('/admin/tarife');
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT rooms_max FROM tiers WHERE code = 'free'"));
    }

    public function test_a_save_without_changes_dispatches_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $purges = $this->purger()->listings;
        $before = $this->tierRow('pro');
        $crawler = $this->client->request('GET', '/admin/tarife/pro');

        $this->client->submit($this->form($crawler), ['tier[name]' => ' Pro ']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/tarife');
        self::assertSame('Keine Änderungen an «Pro».', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame($before, $this->tierRow('pro'));
        self::assertSame($purges, $this->purger()->listings);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'tier'"));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}> input, the field's error id, the message
     */
    public static function invalidInput(): iterable
    {
        yield 'no name' => [['tier[name]' => ''], 'tier_name', 'Bitte einen Namen eingeben.'];
        yield 'a name of 61 characters' => [['tier[name]' => str_repeat('a', 61)], 'tier_name', 'Bitte höchstens 60 Zeichen eingeben.'];
        yield 'no price' => [['tier[priceRappen]' => ''], 'tier_priceRappen', 'Bitte den Preis eingeben.'];
        yield 'a price that is no amount' => [['tier[priceRappen]' => 'abc'], 'tier_priceRappen', 'Bitte den Preis in Franken eingeben, zum Beispiel «199» oder «199.50».'];
        yield 'a price with three decimals' => [['tier[priceRappen]' => '199.999'], 'tier_priceRappen', 'Bitte den Preis in Franken eingeben, zum Beispiel «199» oder «199.50».'];
        yield 'a negative price' => [['tier[priceRappen]' => '-1'], 'tier_priceRappen', 'Bitte den Preis in Franken eingeben, zum Beispiel «199» oder «199.50».'];
        yield 'a price above CHF 100000' => [['tier[priceRappen]' => '100000.01'], 'tier_priceRappen', 'Bitte einen Preis zwischen 0 und 100\'000 Franken eingeben.'];
        yield 'a rank weight above 100' => [['tier[rankWeight]' => '101'], 'tier_rankWeight', 'Bitte eine Zahl zwischen 0 und 100 eingeben.'];
        yield 'a negative photo limit' => [['tier[photosMax]' => '-1'], 'tier_photosMax', 'Bitte eine Zahl zwischen 0 und 1000 eingeben.'];
        yield 'a room limit above 1000' => [['tier[roomsMax]' => '1001'], 'tier_roomsMax', 'Bitte eine Zahl zwischen 0 und 1000 eingeben.'];
        yield 'a room limit that is no number' => [['tier[roomsMax]' => 'viele'], 'tier_roomsMax', 'Bitte eine ganze Zahl eingeben.'];
        yield 'no video limit' => [['tier[videosMax]' => ''], 'tier_videosMax', 'Bitte eine Zahl eingeben.'];
        yield 'a document limit above 1000' => [['tier[documentsMax]' => '1001'], 'tier_documentsMax', 'Bitte eine Zahl zwischen 0 und 1000 eingeben.'];
        yield 'zero event types' => [['tier[eventTypesMax]' => '0'], 'tier_eventTypesMax', 'Bitte eine Zahl zwischen 1 und 1000 eingeben.'];
        yield 'event types that are no number' => [['tier[eventTypesMax]' => 'alle'], 'tier_eventTypesMax', 'Bitte eine ganze Zahl eingeben.'];
        yield 'a NUL in the name' => [['tier[name]' => "Pr\0o"], 'tier_name', 'Der Text enthält ungültige Zeichen.'];
        yield 'the name of another tier' => [['tier[name]' => 'Free'], 'tier_name', 'Diesen Namen hat schon ein anderer Tarif.'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_a_422_on_its_field_and_stores_nothing(array $input, string $field, string $message): void
    {
        $this->logInAs(AdminRole::Editor);
        $purges = $this->purger()->listings;
        $before = $this->tierRow('pro');
        $crawler = $this->client->request('GET', '/admin/tarife/pro');

        $crawler = $this->client->submit($this->form($crawler), $input);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($message, $crawler->filter('#'.$field.'_error')->text());
        self::assertSame('true', $crawler->filter('#'.$field)->attr('aria-invalid'));
        self::assertSame($before, $this->tierRow('pro'));
        self::assertSame($purges, $this->purger()->listings);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'tier'"));
    }

    public function test_a_name_taken_after_the_form_check_is_still_a_422_on_the_name(): void
    {
        $this->logInAs(AdminRole::Editor);
        $crawler = $this->client->request('GET', '/admin/tarife/pro');
        $purges = $this->purger()->listings;
        // A second session renames Free to the name Pro is about to take, after the form looked and before the key.
        $this->entityManager()->getEventManager()->addEventListener([Events::onFlush], new class($this->connection) {
            private bool $fired = false;

            public function __construct(private readonly Connection $connection)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if (!$this->fired) {
                    $this->fired = true;
                    $this->connection->executeStatement("UPDATE tiers SET name = 'Race' WHERE code = 'free'");
                }
            }
        });

        $crawler = $this->client->submit($this->form($crawler), ['tier[name]' => 'Race']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Diesen Namen hat schon ein anderer Tarif.', $crawler->filter('#tier_name_error')->text());
        self::assertSame('Race', $crawler->filter('#tier_name')->attr('value'));
        self::assertSame(['Free', 'Pro'], $this->connection->fetchFirstColumn('SELECT name FROM tiers ORDER BY code'));
        self::assertSame($purges, $this->purger()->listings);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'tier'"));
    }

    public function test_a_refused_save_keeps_what_was_typed(): void
    {
        $this->logInAs(AdminRole::Editor);
        $crawler = $this->client->request('GET', '/admin/tarife/pro');

        $crawler = $this->client->submit($this->form($crawler), ['tier[name]' => 'Pro Plus', 'tier[priceRappen]' => '249.50', 'tier[rankWeight]' => '101']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Pro Plus', $crawler->filter('#tier_name')->attr('value'));
        self::assertSame('249.50', $crawler->filter('#tier_priceRappen')->attr('value'));
        self::assertSame('101', $crawler->filter('#tier_rankWeight')->attr('value'));
    }

    public function test_a_post_without_the_form_is_a_422_and_stores_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $before = $this->tierRow('pro');

        $this->client->request('POST', '/admin/tarife/pro');

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->tierRow('pro'));
    }

    public function test_a_post_without_the_csrf_token_is_refused_and_stores_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $before = $this->tierRow('pro');
        $crawler = $this->client->request('GET', '/admin/tarife/pro');
        $values = $this->form($crawler)->getPhpValues();
        $values['tier']['photosMax'] = '99';
        unset($values['tier']['_token']);

        $this->client->request('POST', '/admin/tarife/pro', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->tierRow('pro'));
    }

    public function test_a_code_no_tier_has_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['gold', 'free_', 'a1', 'Free', '1free', 'free-pro'] as $code) {
            $this->client->request('GET', '/admin/tarife/'.$code);
            self::assertResponseStatusCodeSame(404, $code);

            $this->client->request('POST', '/admin/tarife/'.$code);
            self::assertResponseStatusCodeSame(404, $code);
        }
    }

    public function test_the_screens_are_never_cached_or_indexed(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['/admin/tarife', '/admin/tarife/pro'] as $path) {
            $this->client->request('GET', $path);
            $this->assertNotCachedOrIndexed($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tierRow(string $code): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tiers WHERE code = ?', [$code]);
        self::assertIsArray($row);

        return $row;
    }

    private function venuesOn(string $code): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE t.code = ?', [$code]);
    }

    /**
     * The row's cells as text, the name cell as the link's text alone (the "Top-Eintrag" pill sits beside it).
     *
     * @return list<string>
     */
    private function cells(Crawler $row): array
    {
        $cells = $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text());
        $cells[0] = $row->filter('td')->eq(0)->filter('a')->text();

        return $cells;
    }

    private function form(Crawler $crawler): Form
    {
        return $crawler->filter('form#tier-form')->form();
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
