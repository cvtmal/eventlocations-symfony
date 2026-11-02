<?php

declare(strict_types=1);

namespace App\Tests\Application\Media;

use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * venue_picture() and document_url() through the kernel's Twig (no page uses them before
 * #7/#8): sources per stored format, the JPEG fallback, loading rules, the CSP-safe
 * placeholder and escaping.
 */
final class VenuePictureTest extends WebTestCase
{
    private const string ID = '019a0000-0000-7000-8000-000000000401';

    public function test_renders_avif_and_webp_sources_and_a_jpeg_img(): void
    {
        $html = $this->render('{{ venue_picture(picture, widths=[360, 640, 960]) }}', self::picture());
        $crawler = new Crawler($html);

        $sources = $crawler->filter('span.picture > picture > source');
        self::assertSame(['image/avif', 'image/webp'], $sources->each(static fn (Crawler $source): ?string => $source->attr('type')));
        foreach (['avif' => 0, 'webp' => 1] as $extension => $index) {
            self::assertSame(
                \sprintf('/media/7/%1$s-360.%2$s 360w, /media/7/%1$s-640.%2$s 640w, /media/7/%1$s-960.%2$s 960w', self::ID, $extension),
                $sources->eq($index)->attr('srcset'),
            );
            self::assertSame('(min-width: 900px) 520px, 100vw', $sources->eq($index)->attr('sizes'));
        }

        $img = $crawler->filter('span.picture > picture > img.picture__img');
        self::assertCount(1, $img);
        self::assertSame('/media/7/'.self::ID.'-640.jpg', $img->attr('src'));
        self::assertSame(\sprintf('/media/7/%1$s-360.jpg 360w, /media/7/%1$s-640.jpg 640w, /media/7/%1$s-960.jpg 960w', self::ID), $img->attr('srcset'));
        self::assertSame('1600', $img->attr('width'));
        self::assertSame('1067', $img->attr('height'));
        self::assertSame('Festsaal', $img->attr('alt'));
        self::assertSame('lazy', $img->attr('loading'));
        self::assertSame('async', $img->attr('decoding'));
        self::assertNull($img->attr('fetchpriority'));
    }

    public function test_without_a_widths_restriction_every_stored_width_is_a_candidate(): void
    {
        $crawler = new Crawler($this->render('{{ venue_picture(picture) }}', self::picture()));

        self::assertSame(4, substr_count((string) $crawler->filter('img')->attr('srcset'), 'w,') + 1);
        self::assertStringEndsWith('-1280.jpg 1280w', (string) $crawler->filter('img')->attr('srcset'));
        self::assertStringEndsWith('-640.jpg', (string) $crawler->filter('img')->attr('src'));
    }

    public function test_priority_loads_eagerly_with_high_fetch_priority(): void
    {
        $img = (new Crawler($this->render("{{ venue_picture(picture, sizes='100vw', priority=true) }}", self::picture())))->filter('img');

        self::assertSame('eager', $img->attr('loading'));
        self::assertSame('high', $img->attr('fetchpriority'));
        self::assertNull($img->attr('decoding'));
        self::assertSame('100vw', $img->attr('sizes'));
    }

    public function test_eager_without_priority_yields_to_the_lcp_image(): void
    {
        $img = (new Crawler($this->render("{{ venue_picture(picture, loading='eager') }}", self::picture())))->filter('img');

        self::assertSame('eager', $img->attr('loading'));
        self::assertSame('async', $img->attr('decoding'));
        self::assertSame('low', $img->attr('fetchpriority'));
    }

    public function test_decorative_prints_an_empty_alt(): void
    {
        $img = (new Crawler($this->render('{{ venue_picture(picture, decorative=true) }}', self::picture())))->filter('img');

        self::assertSame('', $img->attr('alt'));
    }

    public function test_lazy_has_no_fetch_priority(): void
    {
        $img = (new Crawler($this->render('{{ venue_picture(picture) }}', self::picture())))->filter('img');

        self::assertSame('lazy', $img->attr('loading'));
        self::assertNull($img->attr('fetchpriority'));
    }

    public function test_a_photo_without_avif_gets_no_avif_source(): void
    {
        $picture = self::picture(new ImageVariants([360, 640], [ImageFormat::Webp, ImageFormat::Jpeg]));

        $crawler = new Crawler($this->render('{{ venue_picture(picture) }}', $picture));

        self::assertSame(['image/webp'], $crawler->filter('source')->each(static fn (Crawler $source): ?string => $source->attr('type')));
        self::assertStringNotContainsString('.avif', $crawler->html());
    }

    public function test_placeholder_is_an_svg_fill_and_nothing_has_a_style_attribute(): void
    {
        $crawler = new Crawler($this->render("{{ venue_picture(picture, class='carousel__image') }}", self::picture()));

        $placeholder = $crawler->filter('span.picture.carousel__image > svg.picture__placeholder');
        self::assertCount(1, $placeholder);
        self::assertSame('true', $placeholder->attr('aria-hidden'));
        self::assertCount(1, $placeholder->filter('rect[fill="#b9a58c"]'));
        self::assertCount(0, $crawler->filter('[style]'));
    }

    public function test_alt_is_escaped(): void
    {
        $picture = new Picture(7, self::ID, 1600, 1067, '#b9a58c', self::picture()->variants, 'Saal "Rose" <gross>');

        $html = $this->render('{{ venue_picture(picture) }}', $picture);

        self::assertStringContainsString('alt="Saal &quot;Rose&quot; &lt;gross&gt;"', $html);
        self::assertSame('Saal "Rose" <gross>', (new Crawler($html))->filter('img')->attr('alt'));
    }

    public function test_a_single_small_width_is_the_src(): void
    {
        $picture = self::picture(new ImageVariants([300], [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg]));

        $img = (new Crawler($this->render('{{ venue_picture(picture, widths=[360, 640]) }}', $picture)))->filter('img');

        self::assertSame('/media/7/'.self::ID.'-300.jpg', $img->attr('src'));
        self::assertSame('/media/7/'.self::ID.'-300.jpg 300w', $img->attr('srcset'));
    }

    public function test_src_falls_back_to_the_smallest_candidate_above_640(): void
    {
        $img = (new Crawler($this->render('{{ venue_picture(picture, widths=[960, 1280]) }}', self::picture())))->filter('img');

        self::assertSame('/media/7/'.self::ID.'-960.jpg', $img->attr('src'));
    }

    public function test_document_url(): void
    {
        self::assertSame('/media/7/abc.pdf', $this->render("{{ document_url(7, 'abc') }}", self::picture()));
    }

    public function test_rejects_an_unknown_loading_value(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('loading is "lazy" or "eager"');

        $this->render("{{ venue_picture(picture, loading='auto') }}", self::picture());
    }

    private static function picture(?ImageVariants $variants = null): Picture
    {
        return new Picture(7, self::ID, 1600, 1067, '#b9a58c', $variants ?? new ImageVariants([360, 640, 960, 1280], [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg]), 'Festsaal');
    }

    private function render(string $template, Picture $picture): string
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate($template)->render(['picture' => $picture]);
    }
}
