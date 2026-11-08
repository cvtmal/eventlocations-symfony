<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueSlugHistoryFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class VenuePreviewTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_an_unpublished_venue_previews_with_the_banner_and_no_inquiry_form(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::full($this->em());
        $this->em()->flush();
        $this->unpublish($venue);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Vorschau: Schloss Seeblick', $crawler->filter('title')->text());
        self::assertStringContainsString('Gäste sehen sie nur, wenn die Location publiziert ist.', $crawler->filter('.preview-banner')->text());
        self::assertSame($this->editorUrl($venue), $crawler->filter('.preview-banner a')->attr('href'));
        self::assertStringStartsWith('Schloss Seeblick', $crawler->filter('main h1')->text());
        self::assertSame('In der Vorschau ist das Anfrageformular ausgeschaltet.', $crawler->filter('#anfrage')->text());
        self::assertSelectorNotExists('form.inquiry-form__form');
        // Nothing for search engines or social previews.
        self::assertSelectorNotExists('link[rel="canonical"]');
        self::assertSelectorNotExists('link[rel="alternate"]');
        self::assertSelectorNotExists('script[type="application/ld+json"]');
        self::assertSelectorNotExists('meta[property^="og:"]');
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        // The public page itself stays gone.
        $this->client->request('GET', '/eventlokal/'.DetailVenues::FULL_SLUG);
        self::assertResponseStatusCodeSame(410);
    }

    public function test_a_google_place_shows_a_note_and_loads_nothing_from_google(): void
    {
        $saved = [$_SERVER['GOOGLE_MAPS_JS_KEY'] ?? null, $_ENV['GOOGLE_MAPS_JS_KEY'] ?? null];
        $savedSwitch = [$_SERVER['GOOGLE_RATING_ENABLED'] ?? null, $_ENV['GOOGLE_RATING_ENABLED'] ?? null];
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $_ENV['GOOGLE_MAPS_JS_KEY'] = 'test-key';
        $_SERVER['GOOGLE_RATING_ENABLED'] = $_ENV['GOOGLE_RATING_ENABLED'] = '1';
        try {
            $this->logInAs(AdminRole::Editor);
            $venue = DetailVenues::full($this->em());
            $this->em()->flush();
            $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$venue->id()]);

            $crawler = $this->client->request('GET', $this->url($venue));

            self::assertResponseIsSuccessful();
            self::assertSame(
                ['Hier zeigt die Website die Google-Bewertung. Die Vorschau lädt sie nicht.'],
                $crawler->filter('.venue-content > .preview-banner__note')->each(static fn (Crawler $note): string => $note->text()),
            );
            self::assertSelectorNotExists('[data-google-rating]');
            self::assertStringNotContainsString('data-maps-key', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('googleapis', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

            $this->connection->executeStatement('UPDATE venues SET google_rating_hidden = TRUE WHERE id = ?', [$venue->id()]);
            $crawler = $this->client->request('GET', $this->url($venue));

            self::assertSame('Die Google-Bewertung ist für diese Location ausgeblendet.', $crawler->filter('.venue-content > .preview-banner__note')->text());
        } finally {
            [$_SERVER['GOOGLE_MAPS_JS_KEY'], $_ENV['GOOGLE_MAPS_JS_KEY']] = $saved;
            [$_SERVER['GOOGLE_RATING_ENABLED'], $_ENV['GOOGLE_RATING_ENABLED']] = $savedSwitch;
        }
    }

    public function test_with_the_rating_switched_off_the_note_says_so(): void
    {
        // D48: GOOGLE_RATING_ENABLED is off in .env and .env.test; the stored place ID stays editable.
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::full($this->em());
        $this->em()->flush();
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$venue->id()]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Die Google-Bewertung ist auf der Website ausgeschaltet.'],
            $crawler->filter('.venue-content > .preview-banner__note')->each(static fn (Crawler $note): string => $note->text()),
        );
        self::assertSelectorNotExists('[data-google-rating]');
    }

    public function test_without_a_google_place_the_preview_has_no_rating_note(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::full($this->em());
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.venue-content > .preview-banner__note'));
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $venue = DetailVenues::minimal();

        $this->client->request('GET', $this->url($venue));

        self::assertResponseRedirects('/admin/login');
    }

    public function test_a_venue_with_a_history_slug_previews(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-gartenhaus']);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Gartenhaus Minimal', $crawler->filter('main h1')->text());
    }

    public function test_a_venue_without_a_place_previews(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $this->unpublish($venue);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Wiesenhof Ohne Ort', $crawler->filter('main h1')->text());
        self::assertSelectorNotExists('#standort');
    }

    public function test_a_venue_without_a_default_translation_or_an_unknown_number_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement('DELETE FROM venue_translations WHERE venue_id = ?', [$venue->id()]);

        $this->client->request('GET', $this->url($venue));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/locations/99999999/vorschau');
        self::assertResponseStatusCodeSame(404);
    }

    private function unpublish(Venue $venue): void
    {
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);
    }

    private function url(Venue $venue): string
    {
        return $this->editorUrl($venue).'/vorschau';
    }

    private function editorUrl(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
