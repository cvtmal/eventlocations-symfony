<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

final class VenueListScreenTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/locations');

        self::assertResponseRedirects('/admin/login');
    }

    public function test_an_editor_sees_every_venue_with_its_columns(): void
    {
        $this->logInAs(AdminRole::Editor);
        $seeblick = $this->venue('Schloss Seeblick', town: 'Meilen', tier: Tier::BeliebteLocation, capacityMax: 220);
        $hut = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Waldhütte Eichholz', 'status' => VenueStatus::Unpublished, 'capacityMax' => 70]);

        $crawler = $this->client->request('GET', '/admin/locations');

        self::assertResponseIsSuccessful();
        self::assertSame(['Schloss Seeblick', 'Waldhütte Eichholz'], $crawler->filter('#venue-list .venue-list__link')->each(static fn (Crawler $link): string => $link->text()));
        $first = $crawler->filter('#venue-list tbody tr')->eq(0);
        self::assertStringContainsString('Nr. '.$seeblick->number().' · Meilen', $first->text());
        self::assertStringContainsString('Beliebte Location', $first->text());
        self::assertStringContainsString('Publiziert', $first->text());
        self::assertSame('/admin/locations/'.$seeblick->number(), $first->filter('.venue-list__link')->attr('href'));
        self::assertCount(1, $first->filter('a[href="/hochzeitslocation/schloss-seeblick"]'));
        self::assertCount(1, $first->filter('a[href="/admin/locations/'.$seeblick->number().'/vorschau"]'));

        // A venue without a town is a row of its own; unpublished, so no link to the public page.
        $second = $crawler->filter('#venue-list tbody tr')->eq(1);
        self::assertSame('Nr. '.$hut->number(), $second->filter('.venue-list__name .muted')->text());
        self::assertStringContainsString('Unpubliziert', $second->text());
        self::assertStringContainsString('Unvollständig', $second->text());
        self::assertCount(0, $second->filter('a[href^="/hochzeitslocation/"]'));
        // Every row has as many cells as the header.
        self::assertSame(7, $crawler->filter('#venue-list thead th')->count());
        self::assertSame(7, $second->filter('td')->count());
    }

    public function test_the_tabs_count_and_filter_by_status(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->venue('Alte Spinnerei');
        VenueFactory::new()->withoutLocation(null)->create(['name' => 'Kulturhaus Weidli', 'status' => VenueStatus::Unpublished]);

        $crawler = $this->client->request('GET', '/admin/locations');
        self::assertSame(['Alle2', 'Publiziert1', 'Unpubliziert1', 'Unvollständig1'], $crawler->filter('nav.tabs a')->each(static fn (Crawler $tab): string => str_replace([' ', "\n"], '', $tab->text())));
        self::assertSame('page', $crawler->filter('nav.tabs a')->eq(0)->attr('aria-current'));

        $crawler = $this->client->request('GET', $crawler->filter('nav.tabs a')->eq(2)->attr('href') ?? '');
        self::assertSame(['Kulturhaus Weidli'], $crawler->filter('#venue-list .venue-list__link')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('page', $crawler->filter('nav.tabs a')->eq(2)->attr('aria-current'));
        self::assertSame('unpublished', $crawler->filter('.filter-bar input[name="status"]')->attr('value'));
    }

    public function test_filters_show_chips_whose_links_remove_them(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->venue('Schloss Seeblick', town: 'Meilen', tier: Tier::Premium);
        $this->venue('Gasthof Rössli', town: 'Bern');
        VenueFactory::new()->withoutLocation(null)->create(['name' => 'Ohne Alles']);
        VenueFactory::new()->withoutLocation(repository(Canton::class)->find('zh'))->create(['name' => 'Nur Zürich']);

        $crawler = $this->client->request('GET', '/admin/locations?tier=premium&canton=zh');
        self::assertResponseIsSuccessful();
        self::assertSame(['Schloss Seeblick'], $this->names($crawler));
        self::assertSame(['Tier: Premium', 'Kanton: Zürich'], $crawler->filter('.filter-chip')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame('premium', $crawler->filter('#tier option[selected]')->attr('value'));
        $remove = $crawler->filter('.filter-chip__remove')->eq(0);
        self::assertSame('Filter «Tier: Premium» entfernen', $remove->attr('aria-label'));

        $crawler = $this->client->request('GET', $remove->attr('href') ?? '');
        self::assertSame(['Nur Zürich', 'Schloss Seeblick'], $this->names($crawler));

        $crawler = $this->client->request('GET', '/admin/locations?canton=none');
        self::assertSame(['Ohne Alles'], $this->names($crawler));
        self::assertSame(['Kanton: Ohne Kanton'], $crawler->filter('.filter-chip')->each(static fn (Crawler $chip): string => $chip->text()));

        $crawler = $this->client->request('GET', '/admin/locations?address=missing');
        self::assertSame(['Nur Zürich', 'Ohne Alles'], $this->names($crawler));

        $crawler = $this->client->request('GET', '/admin/locations?q=meilen');
        self::assertSame(['Schloss Seeblick'], $this->names($crawler));
        self::assertSame('meilen', $crawler->filter('#venue-filter-q')->attr('value'));
        self::assertSame(['Suche: «meilen»'], $crawler->filter('.filter-chip')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function test_invalid_filters_give_the_unfiltered_list_at_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->venue('Schloss Seeblick');

        foreach (['tier=gold', 'canton=xx', 'status=draft', 'q=a%00b', 'q=%ff', 'q[]=x', 'tier[]=premium'] as $query) {
            $crawler = $this->client->request('GET', '/admin/locations?'.$query);

            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame(['Schloss Seeblick'], $this->names($crawler), $query);
            self::assertCount(0, $crawler->filter('.filter-chip'), $query);
            self::assertSame('Ein Filter war ungültig. Die Liste zeigt alle Einträge.', $crawler->filter('main .form-error[role="alert"]')->text(), $query);
        }
        $crawler = $this->client->request('GET', '/admin/locations?tier=premium');
        self::assertCount(0, $crawler->filter('main .form-error'));
        foreach (['sort[]=x', 'dir[]=x', 'page[]=2', 'sort=nope&dir=up&page=abc'] as $query) {
            $this->client->request('GET', '/admin/locations?'.$query);

            self::assertResponseIsSuccessful($query);
        }
    }

    public function test_sort_links_carry_aria_sort(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->venue('Alte Spinnerei', capacityMax: 450);
        $this->venue('Zunfthaus zur Linde', capacityMax: 300);

        $crawler = $this->client->request('GET', '/admin/locations?sort=capacity&dir=desc');

        self::assertSame(['Alte Spinnerei', 'Zunfthaus zur Linde'], $this->names($crawler));
        $header = $crawler->filter('#venue-list th[aria-sort]');
        self::assertCount(1, $header);
        self::assertSame('descending', $header->attr('aria-sort'));
        self::assertStringContainsString('sort=capacity&dir=asc', $header->filter('a')->attr('href') ?? '');

        $crawler = $this->client->request('GET', '/admin/locations?sort=capacity&dir=asc');
        self::assertSame(['Zunfthaus zur Linde', 'Alte Spinnerei'], $this->names($crawler));
        // The default sort is the name, marked without a query.
        $crawler = $this->client->request('GET', '/admin/locations');
        self::assertSame('ascending', $crawler->filter('#venue-list th')->eq(0)->attr('aria-sort'));
    }

    public function test_pagination_and_a_page_past_the_end(): void
    {
        $this->logInAs(AdminRole::Editor);
        VenueFactory::new()->withoutLocation(null)->many(51)->create(static fn (int $i): array => ['name' => \sprintf('Location %02d', $i)]);

        $crawler = $this->client->request('GET', '/admin/locations');
        self::assertCount(50, $crawler->filter('#venue-list tbody tr'));
        self::assertStringContainsString('1 – 50 von 51 Resultaten', $crawler->filter('.pagination')->text());
        $next = $crawler->filter('.pagination a[rel="next"]');
        self::assertSame('Weiter', $next->text());

        $crawler = $this->client->request('GET', $next->attr('href') ?? '');
        self::assertSame(['Location 51'], $this->names($crawler));

        $crawler = $this->client->request('GET', '/admin/locations?page=99');
        self::assertResponseIsSuccessful();
        self::assertSame(['Location 51'], $this->names($crawler));
    }

    public function test_the_empty_state(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/locations?q=nirgendwo');

        self::assertResponseIsSuccessful();
        self::assertSame('Keine Einträge', $crawler->filter('.empty__title')->text());
        self::assertSame('7', $crawler->filter('.table__empty')->attr('colspan'));
    }

    public function test_the_page_links_to_create(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/locations');

        self::assertSame('/admin/locations/neu', $crawler->filter('.page-header__actions a')->attr('href'));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('#venue-list .venue-list__link')->each(static fn (Crawler $link): string => $link->text());
    }

    /**
     * A published venue with nothing missing, named in its slug.
     */
    private function venue(string $name, string $town = 'Bern', Tier $tier = Tier::Basic, int $capacityMax = 120): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'tier' => $tier, 'status' => VenueStatus::Published, 'capacityMax' => $capacityMax, 'town' => DetailVenues::town($town)]);
        $venue->changeContact(null, 'info@example.ch', null, null);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']))), '-');
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein Ort für die Hochzeit.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        return $venue;
    }
}
