<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The venue group of the global search (`Cms\Extension\VenueSearchProvider`) through `/admin/api/search`.
 */
final class VenueSearchProviderTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_a_name_finds_the_venue_with_its_number_place_and_editor_link(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue('Schloss Seeblick', 'Meilen');

        $this->client->request('GET', '/admin/api/search?q=seeblick');

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'label' => 'Einträge',
            'results' => [[
                'type' => 'venue',
                'label' => 'Schloss Seeblick',
                'detail' => 'Nr. '.$venue->number().' · Meilen',
                'url' => '/admin/locations/'.$venue->number(),
            ]],
        ]], $this->searchGroups());
    }

    public function test_a_town_and_a_number_find_it_too(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->venue('Schloss Seeblick', 'Meilen');
        $this->venue('Gartenhaus Aare', 'Bern');

        $this->client->request('GET', '/admin/api/search?q=meilen');
        $byTown = $this->labels();
        $this->client->request('GET', '/admin/api/search?q='.$venue->number());
        $byNumber = $this->labels();

        self::assertSame(['Schloss Seeblick'], $byTown);
        self::assertSame(['Schloss Seeblick'], $byNumber);
    }

    public function test_an_unpublished_venue_without_a_place_is_found(): void
    {
        $this->logInAs(AdminRole::Editor);
        $hut = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Waldhütte Eichholz', 'status' => VenueStatus::Unpublished]);

        $this->client->request('GET', '/admin/api/search?q=waldhuette');

        $result = $this->searchGroups()[0]['results'][0];
        self::assertSame('Waldhütte Eichholz', $result['label']);
        self::assertSame('Nr. '.$hut->number(), $result['detail']);
    }

    public function test_no_match_leaves_no_group(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->venue('Schloss Seeblick', 'Meilen');

        $this->client->request('GET', '/admin/api/search?q=zzzz');

        self::assertSame([], $this->searchGroups());
    }

    private function venue(string $name, string $town): Venue
    {
        return VenueFactory::createOne(['name' => $name, 'status' => VenueStatus::Published, 'town' => DetailVenues::town($town)]);
    }

    /**
     * @return list<string>
     */
    private function labels(): array
    {
        $groups = $this->searchGroups();
        self::assertCount(1, $groups);

        return array_column($groups[0]['results'], 'label');
    }

    /**
     * @return list<array{label: string, results: list<array{type: string, label: string, detail: ?string, url: string}>}>
     */
    private function searchGroups(): array
    {
        /** @var array{groups: list<array{label: string, results: list<array{type: string, label: string, detail: ?string, url: string}>}>} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer['groups'];
    }
}
