<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueVideoFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's Videos tab (G15): staff's pages inside the location layout, with the plan card in the aside. A link
 * adds a video, the plan's limit refuses the next with the plan named, and the tab is marked. Access is the voter's
 * (`LocationRoutesAccessTest` walks these routes too).
 */
final class LocationVideosTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    public function test_a_manager_adds_a_video_and_the_plan_card_counts_it(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal(tier: KnownTiers::PRO));

        $crawler = $this->client->request('GET', $this->url($venue));
        self::assertResponseIsSuccessful();
        self::assertSame('Videos', $crawler->filter('[aria-current="page"]')->last()->text());
        self::assertCount(1, $crawler->filter('aside.shell__aside #location-plan'));
        $this->client->submit($crawler->filter('form[name="video_url"]')->form(), ['video_url[url]' => 'https://vimeo.com/76979871']);

        self::assertResponseRedirects($this->url($venue));
        $crawler = $this->client->followRedirect();
        self::assertSame('Video hinzugefügt.', $crawler->filter('.toast')->text());
        self::assertSame('vimeo', $this->connection->fetchOne('SELECT provider FROM venue_videos WHERE venue_id = ?', [$venue->id()]));
        self::assertStringContainsString('Videos 1 von 5', $crawler->filter('section[aria-labelledby="location-plan"]')->text());
    }

    public function test_free_refuses_the_second_video_with_the_plan_named(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $crawler = $this->client->request('GET', $this->url($venue));
        $crawler = $this->client->submit($crawler->filter('form[name="video_url"]')->form(), ['video_url[url]' => 'https://youtu.be/aB3_dE-fGh1']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Mit dem Plan Free ist höchstens 1 Video möglich.', $crawler->filter('form[name="video_url"]')->text());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_videos WHERE venue_id = ?', [$venue->id()]));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'unlisted Vimeo' => ['https://player.vimeo.com/video/76979871?h=abcdef1234', 'Dieses Vimeo-Video ist nicht öffentlich. Stell es bei Vimeo auf «öffentlich» oder nimm einen YouTube-Link.', 'Diesen Link können wir nicht einbinden.'];
        yield 'another site' => ['https://example.com/video.mp4', 'Diesen Link können wir nicht einbinden.', 'Vimeo-Video ist nicht öffentlich'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedLinks')]
    public function test_a_refused_link_tells_the_manager_why(string $url, string $message, string $notShown): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal(tier: KnownTiers::PRO));

        $crawler = $this->client->request('GET', $this->url($venue));
        $crawler = $this->client->submit($crawler->filter('form[name="video_url"]')->form(), ['video_url[url]' => $url]);

        self::assertResponseStatusCodeSame(422);
        $form = $crawler->filter('form[name="video_url"]')->text();
        self::assertStringContainsString($message, $form);
        self::assertStringNotContainsString($notShown, $form);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_videos WHERE venue_id = ?', [$venue->id()]));
    }

    private function managedVenue(Venue $venue): Venue
    {
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/videos';
    }
}
