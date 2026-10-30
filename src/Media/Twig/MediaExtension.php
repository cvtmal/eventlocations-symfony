<?php

declare(strict_types=1);

namespace App\Media\Twig;

use App\Media\MediaPaths;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\Picture;
use Twig\Attribute\AsTwigFunction;

/**
 * `{{ venue_picture(picture, sizes='(min-width: 900px) 520px, 100vw', loading='lazy', priority=false, widths=null, class='') }}`
 * renders a venue photo (S9):
 *
 *     <span class="picture"><svg class="picture__placeholder" aria-hidden="true"><rect … fill="#rrggbb"/></svg>
 *     <picture><source type="image/avif" srcset sizes><source type="image/webp" srcset sizes>
 *     <img class="picture__img" src srcset sizes width height alt loading decoding></picture></span>
 *
 * - Sources only for the formats the photo has (no AVIF on a host without it).
 * - `widths` restricts the candidates (cards: [360, 640, 960], the listing HTML budget).
 * - `priority: true` is for the one LCP image: eager plus fetchpriority="high". LP-3's second
 *   card passes `loading: 'eager'`. Everything else is lazy.
 * - The placeholder is an SVG `fill`, not a `style` attribute (CSP). `<img width height>` is
 *   the oriented original's size, so the box has its aspect ratio before the image loads.
 *
 * `{{ document_url(venueNumber, documentId) }}` is the PDF's URL, `/media/<number>/<id>.pdf`.
 */
final class MediaExtension
{
    /** The `src` fallback is the largest candidate up to this width. */
    private const int SRC_WIDTH = 640;

    /**
     * @param list<int>|null $widths
     *
     * @throws \InvalidArgumentException when loading is neither "lazy" nor "eager"
     */
    #[AsTwigFunction('venue_picture', isSafe: ['html'])]
    public function venuePicture(
        Picture $picture,
        string $sizes = '(min-width: 900px) 520px, 100vw',
        string $loading = 'lazy',
        bool $priority = false,
        ?array $widths = null,
        string $class = '',
    ): string {
        if (!\in_array($loading, ['lazy', 'eager'], true)) {
            throw new \InvalidArgumentException(\sprintf('venue_picture() loading is "lazy" or "eager", got "%s".', $loading));
        }

        $candidates = null === $widths
            ? $picture->variants->widths
            : array_values(array_intersect($picture->variants->widths, $widths));
        if ([] === $candidates) {
            $candidates = [$picture->variants->smallest()];
        }

        $sources = '';
        foreach ([ImageFormat::Avif, ImageFormat::Webp] as $format) {
            if ($picture->variants->has($format)) {
                $sources .= \sprintf(
                    '<source type="%s" srcset="%s" sizes="%s">',
                    $format->mimeType(),
                    self::escape(self::srcset($picture, $candidates, $format)),
                    self::escape($sizes),
                );
            }
        }

        $small = array_filter($candidates, static fn (int $width): bool => $width <= self::SRC_WIDTH);
        $src = [] === $small ? $candidates[0] : max($small);
        $behaviour = match (true) {
            $priority => 'loading="eager" fetchpriority="high"',
            default => \sprintf('loading="%s" decoding="async"', $loading),
        };

        return \sprintf(
            '<span class="%s"><svg class="picture__placeholder" aria-hidden="true"><rect width="100%%" height="100%%" fill="%s"/></svg>'
            .'<picture>%s<img class="picture__img" src="%s" srcset="%s" sizes="%s" width="%d" height="%d" alt="%s" %s></picture></span>',
            self::escape(trim('picture '.trim($class))),
            self::escape($picture->dominantColor),
            $sources,
            self::escape($picture->url($src, ImageFormat::Jpeg)),
            self::escape(self::srcset($picture, $candidates, ImageFormat::Jpeg)),
            self::escape($sizes),
            $picture->width,
            $picture->height,
            self::escape($picture->alt),
            $behaviour,
        );
    }

    #[AsTwigFunction('document_url')]
    public function documentUrl(int $venueNumber, string $documentId): string
    {
        return MediaPaths::documentUrl($venueNumber, $documentId);
    }

    /**
     * @param list<int> $widths
     */
    private static function srcset(Picture $picture, array $widths, ImageFormat $format): string
    {
        return implode(', ', array_map(
            static fn (int $width): string => \sprintf('%s %dw', $picture->url($width, $format), $width),
            $widths,
        ));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
