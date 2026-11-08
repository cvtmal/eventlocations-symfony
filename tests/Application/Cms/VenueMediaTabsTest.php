<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Cms\VenueMediaApi;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * #25's record tabs (Fotos 900, Räume 800, Dokumente 700, after #24's Übersicht, then G15's Videos 650, G5's Vorschau-Links 600 and #47's Statistik 500; #53 retired Bewertungen) and the two
 * island host pages, whose mount points carry what the islands need (Step 8.1, 8.2, 8.5).
 */
final class VenueMediaTabsTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const array PAGES = [
        'fotos' => 'Fotos',
        'raeume' => 'Räume',
        'dokumente' => 'Dokumente',
        'videos' => 'Videos',
        'vorschau-links' => 'Vorschau-Links',
        'statistik' => 'Statistik',
    ];

    public function test_the_tabs_follow_the_overview_and_mark_the_current_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create();
        $base = '/admin/locations/'.$venue->number();

        foreach (self::PAGES as $path => $label) {
            $crawler = $this->client->request('GET', $base.'/'.$path);

            self::assertResponseIsSuccessful($path);
            $tabs = $crawler->filter('nav.tabs--record a');
            self::assertSame(['Übersicht', ...array_values(self::PAGES)], $tabs->each(static fn (Crawler $tab): string => $tab->text()), $path);
            self::assertSame([$label], $crawler->filter('nav.tabs--record a[aria-current="page"]')->each(static fn (Crawler $tab): string => $tab->text()), $path);
            self::assertSame($base.'/'.$path, $crawler->filter('nav.tabs--record a[aria-current="page"]')->attr('href'));
            self::assertSame($label, $crawler->filter('.breadcrumbs__item[aria-current="page"]')->text(), $path);
            self::assertStringStartsWith($label.' – ', $crawler->filter('title')->text(), $path);
        }

        $this->client->request('GET', $base);
        self::assertSelectorTextSame('nav.tabs--record a[aria-current="page"]', 'Übersicht');
    }

    public function test_an_unknown_number_is_a_404_on_every_tab(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (array_keys(self::PAGES) as $path) {
            $this->client->request('GET', '/admin/locations/99999999/'.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function test_the_retired_reviews_tab_and_rating_card_are_gone(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create();
        $base = '/admin/locations/'.$venue->number();

        $this->client->request('GET', $base.'/bewertungen');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $base.'/bewertung');
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);

        $crawler = $this->client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[name="venue_rating"], #bewertung'));
        self::assertNotContains('Bewertungen', $crawler->filter('nav.tabs--record a')->each(static fn (Crawler $tab): string => $tab->text()));
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        foreach (array_keys(self::PAGES) as $path) {
            $this->client->request('GET', '/admin/locations/1/'.$path);
            self::assertResponseRedirects('/admin/login', null, $path);
        }
    }

    public function test_the_photo_manager_gets_the_photos_rooms_and_endpoints(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Schloss Seeblick']);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Rittersaal']);
        $cover = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        $number = $venue->number();

        $crawler = $this->client->request('GET', '/admin/locations/'.$number.'/fotos');

        self::assertResponseIsSuccessful();
        $props = self::props($crawler, 'photo-manager');
        self::assertIsArray($props['photos']);
        self::assertCount(2, $props['photos']);
        self::assertIsArray($props['photos'][0]);
        self::assertSame([$cover->id(), null, 1], [$props['photos'][0]['id'], $props['photos'][0]['roomId'], $props['photos'][0]['position']]);
        self::assertSame([['id' => $room->id(), 'name' => 'Rittersaal', 'position' => 1]], $props['rooms']);
        self::assertSame([['code' => 'de', 'label' => 'Deutsch']], $props['locales']);
        self::assertSame(['de', 'Schloss Seeblick', 10_000_000, 'image/jpeg,image/png,image/webp', 700], [$props['defaultLocale'], $props['venueName'], $props['maxBytes'], $props['accept'], $props['altMax']]);
        $api = '/admin/api/cms/venues/'.$number.'/photos';
        $id = VenueMediaApi::ID_PLACEHOLDER;
        self::assertSame($id, $props['idPlaceholder']);
        self::assertSame([
            'upload' => $api,
            'order' => $api.'/order',
            'alt' => $api.'/'.$id.'/alt',
            'room' => $api.'/'.$id.'/room',
            'remove' => $api.'/'.$id,
        ], $props['endpoints']);
        self::assertSame('/admin/locations/'.$number.'/fotos', $props['reloadUrl']);
        self::assertIsArray($props['labels']);
        self::assertSame('Fotos hierher ziehen oder auswählen', $props['labels']['dropLabel']);
        self::assertSame('Ohne Text: «Schloss Seeblick, Bild %position».', $props['labels']['altFallback']);
        self::assertSame('%label ist jetzt an Position %position.', $props['labels']['moved']);
        self::assertNotContains('', $props['labels']);
        self::assertSelectorTextContains('[data-island="photo-manager"] .island-required', 'braucht JavaScript');
    }

    public function test_the_room_editor_gets_the_rooms_vocabularies_and_endpoints(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'heightM' => '4.20']);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Seesaal']);
        $number = $venue->number();

        $crawler = $this->client->request('GET', '/admin/locations/'.$number.'/raeume');

        self::assertResponseIsSuccessful();
        $props = self::props($crawler, 'room-editor');
        self::assertIsArray($props['rooms']);
        self::assertIsArray($props['rooms'][0]);
        self::assertSame([$room->id(), ['de' => 'Seesaal'], '4.20'], [$props['rooms'][0]['id'], $props['rooms'][0]['names'], $props['rooms'][0]['heightM']]);
        self::assertSame(['value' => 'parquet', 'label' => 'Parkett'], self::listItem($props['floorMaterials'], 1));
        self::assertSame(['value' => 'seminar', 'label' => 'Seminar/Parlament'], self::listItem($props['seatingTypes'], 0));
        self::assertSame([100, 5000], [$props['nameMax'], $props['descriptionMax']]);
        $api = '/admin/api/cms/venues/'.$number.'/rooms';
        $id = VenueMediaApi::ID_PLACEHOLDER;
        self::assertSame(['add' => $api, 'order' => $api.'/order', 'change' => $api.'/'.$id, 'remove' => $api.'/'.$id], $props['endpoints']);
        self::assertSame('/admin/locations/'.$number.'/raeume', $props['reloadUrl']);
        self::assertIsArray($props['labels']);
        self::assertSame('Raum hinzufügen', $props['labels']['add']);
        self::assertNotContains('', $props['labels']);
        self::assertSelectorTextContains('[data-island="room-editor"] .island-required', 'braucht JavaScript');
    }

    /**
     * @return array<string, mixed>
     */
    private static function props(Crawler $crawler, string $island): array
    {
        $mount = $crawler->filter(\sprintf('[data-island="%s"]', $island));
        self::assertCount(1, $mount);
        $props = json_decode((string) $mount->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);

        /* @var array<string, mixed> $props */
        return $props;
    }

    /**
     * @return array<mixed>
     */
    private static function listItem(mixed $list, int $index): array
    {
        self::assertIsArray($list);
        self::assertIsArray($list[$index]);

        return $list[$index];
    }
}
