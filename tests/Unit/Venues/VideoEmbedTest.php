<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\VideoEmbed;
use App\Venues\ValueObject\VideoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * G15: the allowlist. A link of YouTube or Vimeo becomes provider + id; anything else, a lookalike host included, is
 * null. The player and watch URLs are built from those two values only.
 */
final class VideoEmbedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, VideoProvider, string}>
     */
    public static function accepted(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'watch with more parameters' => ['https://youtube.com/watch?feature=share&v=aB3_dE-fGh1&t=42s', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'mobile' => ['https://m.youtube.com/watch?v=aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'short link' => ['https://youtu.be/aB3_dE-fGh1?si=xyz', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'no scheme' => ['youtu.be/aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'http and spaces around' => ['  http://www.youtube.com/watch?v=aB3_dE-fGh1 ', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'upper-case host' => ['https://WWW.YouTube.com/watch?v=aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'embed' => ['https://www.youtube.com/embed/aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'shorts' => ['https://www.youtube.com/shorts/aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'live' => ['https://www.youtube.com/live/aB3_dE-fGh1?feature=shared', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'nocookie' => ['https://www.youtube-nocookie.com/embed/aB3_dE-fGh1', VideoProvider::YouTube, 'aB3_dE-fGh1'];
        yield 'vimeo' => ['https://vimeo.com/76979871', VideoProvider::Vimeo, '76979871'];
        yield 'vimeo www and trailing slash' => ['https://www.vimeo.com/76979871/', VideoProvider::Vimeo, '76979871'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?autoplay=1', VideoProvider::Vimeo, '76979871'];
    }

    #[DataProvider('accepted')]
    public function test_an_allowlisted_link_gives_provider_and_id(string $url, VideoProvider $provider, string $id): void
    {
        $embed = VideoEmbed::fromUrl($url);

        self::assertNotNull($embed);
        self::assertSame([$provider, $id], [$embed->provider, $embed->videoId]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'other site' => ['https://example.com/watch?v=aB3_dE-fGh1'];
        yield 'lookalike suffix' => ['https://youtube.com.example.com/watch?v=aB3_dE-fGh1'];
        yield 'lookalike prefix' => ['https://evilyoutube.com/watch?v=aB3_dE-fGh1'];
        yield 'subdomain not listed' => ['https://music.youtube.com/watch?v=aB3_dE-fGh1'];
        yield 'javascript' => ['javascript:alert(1)//youtu.be/aB3_dE-fGh1'];
        yield 'data' => ['data:text/html,<iframe src=https://youtu.be/aB3_dE-fGh1>'];
        yield 'ftp' => ['ftp://youtu.be/aB3_dE-fGh1'];
        yield 'user info' => ['https://evil@youtu.be/aB3_dE-fGh1'];
        yield 'port' => ['https://youtu.be:8443/aB3_dE-fGh1'];
        yield 'id too short' => ['https://youtu.be/aB3_dE'];
        yield 'id too long' => ['https://youtu.be/aB3_dE-fGh12'];
        yield 'id with a quote' => ['https://www.youtube.com/watch?v=aB3_dE"fGh1'];
        yield 'id as an array' => ['https://www.youtube.com/watch?v[]=aB3_dE-fGh1'];
        yield 'channel' => ['https://www.youtube.com/@somechannel'];
        yield 'playlist' => ['https://www.youtube.com/playlist?list=PL1234567890'];
        yield 'embed html' => ['<iframe src="https://www.youtube.com/embed/aB3_dE-fGh1"></iframe>'];
        yield 'vimeo unlisted' => ['https://vimeo.com/76979871/abcdef1234'];
        yield 'vimeo player unlisted' => ['https://player.vimeo.com/video/76979871?h=abcdef1234'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871'];
        yield 'vimeo not a number' => ['https://vimeo.com/about'];
        yield 'vimeo leading zero' => ['https://vimeo.com/076979871'];
        yield 'too long' => ['https://www.youtube.com/watch?v=aB3_dE-fGh1&x='.str_repeat('a', 500)];
    }

    #[DataProvider('refused')]
    public function test_any_other_link_is_refused(string $url): void
    {
        self::assertNull(VideoEmbed::fromUrl($url));
    }

    public function test_the_player_is_youtube_nocookie_or_vimeo_without_tracking(): void
    {
        $youtube = VideoEmbed::fromUrl('https://youtu.be/aB3_dE-fGh1');
        $vimeo = VideoEmbed::fromUrl('https://vimeo.com/76979871');
        self::assertNotNull($youtube);
        self::assertNotNull($vimeo);

        self::assertSame('https://www.youtube-nocookie.com/embed/aB3_dE-fGh1?autoplay=1', $youtube->embedUrl());
        self::assertSame('https://www.youtube.com/watch?v=aB3_dE-fGh1', $youtube->watchUrl());
        self::assertSame('https://player.vimeo.com/video/76979871?dnt=1&autoplay=1', $vimeo->embedUrl());
        self::assertSame('https://vimeo.com/76979871', $vimeo->watchUrl());
    }

    public function test_of_refuses_an_id_of_the_wrong_shape(): void
    {
        self::assertNull(VideoEmbed::of(VideoProvider::YouTube, '76979871'));
        self::assertNull(VideoEmbed::of(VideoProvider::Vimeo, 'aB3_dE-fGh1'));
        self::assertNotNull(VideoEmbed::of(VideoProvider::Vimeo, '76979871'));
    }
}
