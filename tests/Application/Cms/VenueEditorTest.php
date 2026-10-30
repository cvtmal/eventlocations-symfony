<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Cms\ValueObject\VenueCard;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Test\Factories;

final class VenueEditorTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    /**
     * @return iterable<string, array{string}>
     */
    public static function shapes(): iterable
    {
        yield 'full' => ['full'];
        yield 'minimal' => ['minimal'];
        yield 'without a place' => ['none'];
        yield 'canton only' => ['canton'];
    }

    #[DataProvider('shapes')]
    public function test_the_editor_renders_every_card(string $shape): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue($shape);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame($venue->name(), $crawler->filter('.record-header h1')->text());
        self::assertSame('Nr. '.$venue->number(), explode(' · ', $crawler->filter('.record-header__subtitle')->text())[0]);
        foreach (VenueCard::cases() as $card) {
            $form = $crawler->filter('form[name="'.$card->formName().'"]');
            self::assertCount(1, $form, $card->value);
            self::assertSame($this->url($venue).'/'.$this->segment($card), $form->attr('action'), $card->value);
            self::assertSame($card->anchor(), $form->attr('id'), $card->value);
            self::assertSame('', $form->attr('data-unsaved-guard'), $card->value);
        }
        self::assertSame(['Kurztext', 'Haupt-Gebäudeart', 'Mindestens ein Foto'], $crawler->filter('.venue-checklist__item > span:not(.visually-hidden)')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSelectorTextContains('section[aria-labelledby="venue-history"]', 'Noch keine Änderungen');
    }

    public function test_the_header_line_and_actions(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::full($this->em());
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertStringStartsWith('Nr. '.$venue->number().' · Seestrasse 1, 8001 Zürich ZH', $crawler->filter('.record-header__subtitle')->text());
        self::assertSame($this->url($venue).'/vorschau', $crawler->filter('.record-header__actions a')->eq(0)->attr('href'));
        self::assertSame('/hochzeitslocation/schloss-seeblick', $crawler->filter('.record-header__actions a')->eq(1)->attr('href'));
        self::assertSame($this->url($venue).'/unpublizieren', $crawler->filter('form[name="unpublish"]')->attr('action'));
        // Delete is for admins, and only for an unpublished venue.
        self::assertSelectorNotExists('#venue-delete');
        self::assertSelectorNotExists('a[href^="/admin/audit-log"]');
    }

    public function test_overview_is_the_first_tab_and_current(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', $this->url($venue));

        $first = $crawler->filter('.tabs--record .tab')->first();
        self::assertSame('Übersicht', $first->text());
        self::assertSame($this->url($venue), $first->attr('href'));
        self::assertSame('page', $first->attr('aria-current'));
        self::assertSame('Locations', $crawler->filter('.sidebar__item[aria-current="page"] .sidebar__label')->text());
    }

    public function test_an_admin_sees_delete_on_an_unpublished_venue_and_the_whole_history(): void
    {
        $this->logInAs();
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame($this->url($venue).'/publizieren', $crawler->filter('form[name="publish"]')->attr('action'));
        self::assertSame($this->url($venue).'/loeschen', $crawler->filter('#venue-delete form[name="delete"]')->attr('action'));
        self::assertSame('Abbrechen', $crawler->filter('#venue-delete .modal__footer form[method="dialog"] button[autofocus]')->text());
        self::assertSame('venue-delete', $crawler->filter('button[command="show-modal"]')->attr('commandfor'));
        self::assertSelectorNotExists('.record-header__actions a[href^="/hochzeitslocation/"]');
        self::assertSame('/admin/audit-log?subject_type=venue&subject_id='.$venue->number(), $crawler->filter('section[aria-labelledby="venue-history"]')->filter('a')->attr('href'));
    }

    public function test_an_imported_venue_says_when_it_was_imported(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET content_key = 'gartenhaus', content_hash = repeat('a', 64), updated_at = '2026-10-12 08:00:00+00' WHERE id = ?", [$venue->id()]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertStringEndsWith('zuletzt importiert am 12.10.2026', $crawler->filter('.record-header__subtitle')->text());
    }

    public function test_the_town_centre_hint(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement('UPDATE venues SET lat = 46.948, lng = 7.4474, coordinates_from_town = TRUE WHERE id = ?', [$venue->id()]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame('Ortsmitte von Bern, auf der Website ohne Karte', $crawler->filter('#adresse .venue-card__hint')->text());
    }

    /**
     * One card per data set: the client keeps its kernel (no reboot) and each editor request retains a few MB
     * until the test ends, so all eight cards in one test exhausted `memory_limit` in the full suite.
     *
     * @return iterable<string, array{string, VenueCard}>
     */
    public static function shapesAndCards(): iterable
    {
        foreach (self::shapes() as $name => [$shape]) {
            foreach (VenueCard::cases() as $card) {
                yield $name.', '.$card->value => [$shape, $card];
            }
        }
    }

    #[DataProvider('shapesAndCards')]
    public function test_saving_an_untouched_card_is_a_no_op(string $shape, VenueCard $card): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue($shape);
        if ('full' === $shape) {
            $this->connection->executeStatement("UPDATE venue_translations SET description = E'Absatz eins.\\r\\n\\r\\nAbsatz zwei. ' WHERE venue_id = ?", [$venue->id()]);
        }
        $updatedAt = $this->connection->fetchOne('SELECT updated_at FROM venues WHERE id = ?', [$venue->id()]);

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="'.$card->formName().'"]')->form());

        self::assertResponseRedirects($this->url($venue).'#'.$card->anchor());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Keine Änderungen in «', $crawler->filter('.toast')->text());

        self::assertSame(0, $this->auditRows($venue));
        self::assertSame([null, $updatedAt], array_values((array) $this->connection->fetchAssociative('SELECT cms_edited_at, updated_at FROM venues WHERE id = ?', [$venue->id()])));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_save_marks_purges_redirects_and_audits(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="venue_basics"]')->form(), ['venue_basics[capacityMax]' => '95']);

        self::assertResponseRedirects($this->url($venue).'#stammdaten');
        $crawler = $this->client->followRedirect();
        self::assertSame('«Stammdaten» gespeichert.', $crawler->filter('.toast')->text());
        self::assertSame('95', $crawler->filter('#venue_basics_capacityMax')->attr('value'));
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->auditRows($venue));
        $changes = json_decode((string) $this->connection->fetchOne("SELECT changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]), true);
        self::assertIsArray($changes);
        self::assertSame(['capacityMax'], $changes['changed']);
        self::assertStringContainsString('Andrea Keller hat die Stammdaten geändert', $crawler->filter('section[aria-labelledby="venue-history"]')->text());
        self::assertStringContainsString('zuletzt bearbeitet von Andrea Keller', $crawler->filter('.record-header__subtitle')->text());
    }

    public function test_a_stale_save_is_a_409_that_keeps_the_input(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', $this->url($venue));
        $form = $crawler->filter('form[name="venue_basics"]')->form();
        $this->connection->executeStatement("UPDATE venues SET updated_at = updated_at + INTERVAL '1 hour' WHERE id = ?", [$venue->id()]);
        $crawler = $this->client->submit($form, ['venue_basics[capacityMax]' => '95']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('95', $crawler->filter('#venue_basics_capacityMax')->attr('value'));
        self::assertStringContainsString('Diese Location wurde inzwischen geändert', $crawler->filter('#stammdaten')->text());
        self::assertSame($this->url($venue).'#stammdaten', $crawler->filter('#stammdaten .venue-card__stale a')->attr('href'));
        self::assertSelectorNotExists('#texte .venue-card__stale');
        self::assertSame(80, (int) $this->connection->fetchOne('SELECT capacity_max FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_rule_violation_from_the_handler_is_a_field_error(): void
    {
        $this->logInAs(AdminRole::Editor);
        $taken = DetailVenues::full($this->em());
        $venue = DetailVenues::minimal();
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->url($venue));
        $crawler = $this->client->submit($crawler->filter('form[name="venue_basics"]')->form(), ['venue_basics[slug]' => DetailVenues::FULL_SLUG]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#venue_basics_slug')->attr('aria-invalid'));
        self::assertStringContainsString('Diesen Slug hat schon eine andere Location', $crawler->filter('#stammdaten')->text());
        self::assertNotSame($taken->number(), $venue->number());
    }

    public function test_bad_tokens_are_a_422_and_change_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="venue_basics"]')->form(), ['venue_basics[capacityMax]' => '95', 'venue_basics[loadedAt]' => 'abc']);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', $this->url($venue));
        $form = $crawler->filter('form[name="venue_basics"]')->form(['venue_basics[capacityMax]' => '95']);
        $form->remove('venue_basics[_token]');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', $this->url($venue).'/stammdaten');
        self::assertResponseStatusCodeSame(422);

        self::assertSame(80, (int) $this->connection->fetchOne('SELECT capacity_max FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame(0, $this->auditRows($venue));
    }

    public function test_a_422_marks_the_field_and_leaves_the_other_cards_fresh(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', $this->url($venue));
        $crawler = $this->client->submit($crawler->filter('form[name="venue_basics"]')->form(), ['venue_basics[capacityMax]' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#venue_basics_capacityMax')->attr('aria-invalid'));
        self::assertSelectorNotExists('#texte [aria-invalid]');
        self::assertSame('Ein kleines Gartenhaus.', $crawler->filter('#venue_texts_texts_de_summary')->text());
    }

    public function test_unknown_numbers_are_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['GET /admin/locations/999999', 'POST /admin/locations/999999/stammdaten', 'GET /admin/locations/99999999999', 'GET /admin/locations/0', 'GET /admin/locations/01'] as $request) {
            [$method, $url] = explode(' ', $request);
            $this->client->request($method, $url);

            self::assertResponseStatusCodeSame(404, $request);
        }
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/locations/1');

        self::assertResponseRedirects('/admin/login');
    }

    private function venue(string $shape): Venue
    {
        $venue = match ($shape) {
            'full' => DetailVenues::full($this->em()),
            'minimal' => DetailVenues::minimal(),
            'none' => DetailVenues::withoutPlace(),
            'canton' => DetailVenues::inCantonOnly(),
            default => throw new \InvalidArgumentException($shape),
        };
        $this->em()->flush();

        return $venue;
    }

    private function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number();
    }

    private function segment(VenueCard $card): string
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = (string) parse_url($router->generate($card->route(), ['number' => 1]), \PHP_URL_PATH);

        return substr($path, \strlen('/admin/locations/1/'));
    }

    private function auditRows(Venue $venue): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
