<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Factory\VenueVideoFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Videos tab (G15): a link adds a video, a refused link or one above the plan is a 422 with the link kept, the move
 * buttons swap neighbours, remove deletes; each write is POST → redirect → GET with a flash. The page never embeds a
 * player.
 */
final class VenueVideosPageTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_a_link_adds_the_video_and_the_tab_shows_it(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::PRO);

        $crawler = $this->client->request('GET', self::url($venue));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.empty__title', 'Noch keine Videos');
        $this->client->submit($crawler->filter('form[name="video_url"]')->form(), ['video_url[url]' => ' https://youtu.be/aB3_dE-fGh1 ']);

        self::assertResponseRedirects(self::url($venue));
        $crawler = $this->client->followRedirect();
        self::assertSame('Video hinzugefügt.', $crawler->filter('.toast')->text());
        self::assertSame(['youtube', 'aB3_dE-fGh1'], $this->connection->fetchNumeric('SELECT provider, video_id FROM venue_videos WHERE venue_id = ?', [$venue->id()]));
        self::assertSame('Video 1 auf YouTube aB3_dE-fGh1', $crawler->filter('.sortable__label')->text());
        self::assertSame('https://www.youtube.com/watch?v=aB3_dE-fGh1', $crawler->filter('.sortable__actions a')->attr('href'));
        self::assertCount(0, $crawler->filter('iframe, template'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'empty' => ['', 'Bitte einen Link zu einem Video auf YouTube oder Vimeo eingeben.'];
        yield 'another site' => ['https://example.com/video.mp4', 'Diesen Link können wir nicht einbinden.'];
        yield 'unlisted Vimeo' => ['https://vimeo.com/76979871/abcdef1234', 'Dieses Vimeo-Video ist nicht öffentlich. Stell es bei Vimeo auf «Öffentlich» oder nimm einen YouTube-Link.'];
        yield 'embed HTML' => ['<iframe src="https://www.youtube.com/embed/aB3_dE-fGh1"></iframe>', 'Diesen Link können wir nicht einbinden.'];
        yield 'too long' => ['https://youtu.be/'.str_repeat('a', 500), 'höchstens 500 Zeichen'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedLinks')]
    public function test_a_refused_link_is_a_422_with_the_link_kept(string $url, string $message): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::PRO);

        $crawler = $this->client->request('GET', self::url($venue));
        $crawler = $this->client->submit($crawler->filter('form[name="video_url"]')->form(), ['video_url[url]' => $url]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $crawler->filter('form[name="video_url"]')->text());
        if ('' !== $url && \strlen($url) <= 500) {
            self::assertSame($url, $crawler->filter('#video_url_url')->attr('value'));
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_videos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_second_video_on_free_is_refused_with_the_plan_named(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::FREE);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $crawler = $this->client->request('GET', self::url($venue));
        self::assertStringContainsString('Mit dem Plan «Gratis» zeigt die Website 1 Video.', $crawler->filter('section[aria-labelledby="venue-videos"]')->text());
        $crawler = $this->client->submit($crawler->filter('form[name="video_url"]')->form(), ['video_url[url]' => 'https://vimeo.com/76979871']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Mit dem Plan «Gratis» ist höchstens 1 Video möglich.', $crawler->text());
    }

    public function test_a_video_above_the_plan_is_marked_hidden(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::FREE);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenueVideoFactory::createOne(['venue' => $venue, 'position' => 2, 'embed' => VenueVideoFactory::vimeo('76979871')]);

        $crawler = $this->client->request('GET', self::url($venue));

        self::assertSame(
            [false, true],
            $crawler->filter('.sortable__label')->each(static fn (Crawler $label): bool => str_contains($label->text(), 'Nicht sichtbar (Plan)')),
        );
    }

    public function test_the_move_buttons_swap_neighbours_and_remove_deletes(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::PRO);
        $first = VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $second = VenueVideoFactory::createOne(['venue' => $venue, 'position' => 2, 'embed' => VenueVideoFactory::vimeo('76979871')])->id();

        $crawler = $this->client->request('GET', self::url($venue));
        $this->client->submit($crawler->filter(\sprintf('form[action="%s/%s/nach-oben"]', self::url($venue), $second))->form());
        self::assertResponseRedirects(self::url($venue).'#venue-videos');
        self::assertSame('Video 2 ist jetzt an Position 1.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame([$second, $first], $this->order($venue));

        $crawler = $this->client->request('GET', self::url($venue));
        $dialog = $crawler->filter('#video-remove-'.$first);
        self::assertSame('Video 2 wird gelöscht und verschwindet von der Website.', $dialog->filter('.modal__body')->text());
        $this->client->submit($dialog->filter('form[method="post"]')->form());
        self::assertResponseRedirects(self::url($venue));
        self::assertSame('Video 2 gelöscht.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame([$second], $this->order($venue));
    }

    public function test_a_missing_csrf_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::PRO);
        $video = VenueVideoFactory::createOne(['venue' => $venue, 'position' => 1])->id();

        $this->client->request('POST', self::url($venue).'/'.$video.'/loeschen');

        self::assertResponseRedirects(self::url($venue));
        self::assertSame([$video], $this->order($venue));
    }

    public function test_an_unknown_video_or_venue_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue(KnownTiers::PRO);
        $foreign = VenueVideoFactory::createOne()->id();

        $this->client->request('POST', self::url($venue).'/'.$foreign.'/loeschen', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/locations/999999/videos');
        self::assertResponseStatusCodeSame(404);
    }

    private static function venue(string $tier): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
    }

    private static function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number().'/videos';
    }

    /**
     * @return list<string>
     */
    private function order(Venue $venue): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT id FROM venue_videos WHERE venue_id = ? ORDER BY position', [$venue->id()]);
    }
}
