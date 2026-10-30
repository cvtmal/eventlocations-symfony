<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The basics card's slug rules (D9, CMS-2): a new slug or a rename keeps the old URL working with a 301, a taken
 * slug is an error on the field, and the purge covers the venue.
 */
final class VenueBasicsCardTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_a_new_slug_redirects_from_the_old_one(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $this->save($venue, ['slug' => 'gartenhaus-am-hang']);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#stammdaten');
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
        $this->client->request('GET', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseRedirects('/hochzeitslocation/gartenhaus-am-hang', 301);
        $this->client->request('GET', '/hochzeitslocation/gartenhaus-am-hang');
        self::assertResponseIsSuccessful();
    }

    public function test_a_rename_gives_a_new_slug_and_redirects_from_the_old_one(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $this->save($venue, ['name' => 'Gartenpavillon Sonnenhang']);

        self::assertResponseRedirects();
        $slug = (string) $this->connection->fetchOne("SELECT slug FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]);
        self::assertStringStartsWith('gartenpavillon-sonnenhang', $slug);
        $this->client->request('GET', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseRedirects('/hochzeitslocation/'.$slug, 301);
        self::assertSame('Gartenpavillon Sonnenhang', $this->client->followRedirect()->filter('main h1')->text());
    }

    public function test_a_taken_slug_is_an_error_on_the_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        DetailVenues::withoutPlace();

        $crawler = $this->save($venue, ['slug' => 'wiesenhof-ohne-ort']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#venue_basics_slug')->attr('aria-invalid'));
        self::assertStringContainsString('Diesen Slug hat schon eine andere Location', $crawler->filter('#stammdaten')->text());
        self::assertSame('wiesenhof-ohne-ort', $crawler->filter('#venue_basics_slug')->attr('value'));
        self::assertSame(DetailVenues::MINIMAL_SLUG, $this->connection->fetchOne("SELECT slug FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]));
        self::assertSame([], $this->purger()->venues);
    }

    /**
     * @param array<string, string> $values
     */
    private function save(Venue $venue, array $values): Crawler
    {
        $form = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter('form[name="venue_basics"]')->form();
        foreach ($values as $field => $value) {
            $form['venue_basics['.$field.']'] = $value;
        }

        return $this->client->submit($form);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
