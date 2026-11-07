<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\PlainText;
use App\Shared\Http\SafeUrl;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Image references on a page: `img` (also the lazy-loading attributes), `srcset` and `picture`, inline
 * `background-image`, the Open Graph and Twitter images and `link rel="image_src"`. Nothing is downloaded, so the
 * size is what the page declares. Icons, logos, sprites, payment and social badges and pictures declared
 * smaller than 200 x 120 are counted and left out, by name and size only: no image is looked at (D22).
 */
final class ImageReader
{
    private const array LAZY = ['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-full-src'];
    private const string NOISE = '(logo|icon|icons|sprite|favicon|avatar|emoji|spacer|pixel|blank|placeholder|loader|loading|spinner|arrow|button|badge|flag|payment|visa|mastercard|twint|paypal|facebook|instagram|twitter|linkedin|youtube|whatsapp|captcha|tracking|1x1)';
    private const int MAX_PER_PAGE = 80;
    private const int MAX_ELEMENTS = 600;
    /** Beyond this a declared size is a typo or an attack, not a photo. */
    private const int MAX_PIXELS = 20000;
    private const int MIN_WIDTH = 200;
    private const int MIN_HEIGHT = 120;

    /**
     * @param list<string> $jsonLdImages raw references from the page's JSON-LD, listed after the Open Graph images
     *
     * @return array{list<ImageCandidate>, int} the candidates in page order and the number left out
     */
    public function read(Crawler $page, SafeUrl $base, SafeUrl $pageUrl, array $jsonLdImages = []): array
    {
        $images = [];
        $skipped = 0;
        $seen = [];

        $add = function (string $raw, CandidateOrigin $origin, ?string $alt, ?\DOMElement $contextOf, ?int $width, ?int $height, string $hint) use ($base, $pageUrl, &$images, &$skipped, &$seen): void {
            try {
                $url = $base->resolve($raw);
            } catch (FetchRefused) {
                return; // data: URIs, javascript:, hosts that are not a public name
            }
            if ($this->isNoise($url, $hint, $alt, $width, $height)) {
                ++$skipped;

                return;
            }
            $key = self::identity((string) $url);
            if (isset($seen[$key]) || \count($images) >= self::MAX_PER_PAGE) {
                return;
            }
            $seen[$key] = true;
            $alt = null === $alt ? null : PlainText::clean($alt, 500);
            $images[] = new ImageCandidate((string) $url, (string) $pageUrl, $origin, '' === $alt ? null : $alt, null === $contextOf ? null : $this->context($contextOf), $width, $height);
        };

        foreach (DomElements::of($page->filter('meta[property], meta[name]'), 300) as $meta) {
            $name = strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
            $content = trim($meta->getAttribute('content'));
            if ('' !== $content && \in_array($name, ['og:image', 'og:image:url', 'og:image:secure_url'], true)) {
                $add($content, CandidateOrigin::OgImage, null, null, null, null, '');
            } elseif ('' !== $content && 'twitter:image' === $name) {
                $add($content, CandidateOrigin::TwitterImage, null, null, null, null, '');
            }
        }
        foreach (DomElements::of($page->filter('link[rel="image_src"][href]'), 5) as $link) {
            $add($link->getAttribute('href'), CandidateOrigin::LinkRel, null, null, null, null, '');
        }
        foreach ($jsonLdImages as $reference) {
            $add($reference, CandidateOrigin::JsonLd, null, null, null, null, '');
        }

        // A page with thousands of images is not a venue's page; stop reading it once the list is full.
        foreach (DomElements::of($page->filter('img, [style*="url("]'), self::MAX_ELEMENTS) as $element) {
            if (\count($images) >= self::MAX_PER_PAGE) {
                break;
            }
            if ('img' !== strtolower($element->nodeName)) {
                if (1 === preg_match('/background(?:-image)?\s*:[^;]*url\(\s*[\'"]?([^\'")]+)/i', $element->getAttribute('style'), $match)) {
                    $add($match[1], CandidateOrigin::CssBackground, null, $element, null, null, $this->hint($element));
                }
                continue;
            }

            [$srcsetUrl, $srcsetWidth] = $this->largest($element, ['srcset', 'data-srcset', 'data-lazy-srcset']);
            $picture = $element->parentNode;
            $inPicture = $picture instanceof \DOMElement && 'picture' === strtolower($picture->nodeName);
            if ($inPicture) {
                // Several <source> elements are the same photo in other formats or crops: the widest of them
                // competes with the <img>, and the <img> (the format every browser reads) wins a tie.
                foreach ($picture->getElementsByTagName('source') as $source) {
                    [$url, $width] = $this->largest($source, ['srcset', 'data-srcset']);
                    if (null !== $url && (null === $srcsetUrl || ($width ?? 0) > ($srcsetWidth ?? 0))) {
                        [$srcsetUrl, $srcsetWidth] = [$url, $width];
                    }
                }
            }

            $src = '';
            foreach ([...self::LAZY, 'src'] as $attribute) {
                $src = trim($element->getAttribute($attribute));
                if ('' !== $src && !str_starts_with($src, 'data:')) {
                    break;
                }
            }
            $raw = $srcsetUrl ?? $src;
            if ('' === $raw || str_starts_with($raw, 'data:')) {
                continue;
            }

            $width = $this->pixels($element->getAttribute('width')) ?? $srcsetWidth;
            $height = $this->pixels($element->getAttribute('height'));
            if (null !== $srcsetWidth && null !== $width && $srcsetWidth > $width) {
                $width = $srcsetWidth;
                $height = null;
            }
            $alt = $element->getAttribute('alt') ?: $element->getAttribute('title');
            $origin = $inPicture ? CandidateOrigin::Picture : (null !== $srcsetUrl ? CandidateOrigin::Srcset : CandidateOrigin::Img);
            $add($raw, $origin, '' !== trim($alt) ? $alt : null, $element, $width, $height, $this->hint($element));
        }

        return [$images, $skipped];
    }

    /**
     * The widest entry of the first of these srcset attributes that has a usable one: "a.jpg 400w, b.jpg 1200w" gives
     * [b.jpg, 1200]; "a.jpg 1x, b.jpg 2x" the last. An entry is a URL without whitespace (it may hold commas, as CDN
     * URLs do: `/c/w_400,h_300/p.jpg`), optionally followed by a descriptor; `data:` URIs (lazy-load placeholders) are
     * no candidates.
     *
     * @param list<string> $attributes
     *
     * @return array{?string, ?int}
     */
    private function largest(\DOMElement $element, array $attributes): array
    {
        foreach ($attributes as $attribute) {
            $best = [null, null];
            $bestScore = -1.0;
            preg_match_all('/(?:^|,|\s)\s*([^\s,][^\s]*)(?:\s+(\d+(?:\.\d+)?[wx]))?(?=\s*(?:,|$))/', $element->getAttribute($attribute), $entries, \PREG_SET_ORDER);
            foreach (\array_slice($entries, 0, 40) as $entry) {
                $url = rtrim($entry[1], ',');
                if ('' === $url || str_starts_with($url, 'data:')) {
                    continue;
                }
                $descriptor = $entry[2] ?? '1x';
                $score = (float) $descriptor;
                if ($score >= $bestScore) {
                    $bestScore = $score;
                    $best = [$url, str_ends_with($descriptor, 'w') && (int) $descriptor <= self::MAX_PIXELS ? (int) $descriptor : null];
                }
            }
            if (null !== $best[0]) {
                return $best;
            }
        }

        return [null, null];
    }

    /**
     * Two references to one photo (the same name in another size, as WordPress writes `photo-300x200.jpg` beside
     * `photo.jpg`) have the same identity.
     */
    public static function identity(string $imageUrl): string
    {
        try {
            $url = SafeUrl::parse($imageUrl);
        } catch (FetchRefused) {
            return $imageUrl;
        }

        return $url->host.preg_replace('/-\d{2,4}x\d{2,4}(?=\.\w+$)/', '', $url->path).($url->query ?? '');
    }

    private function isNoise(SafeUrl $url, string $hint, ?string $alt, ?int $width, ?int $height): bool
    {
        if (1 === preg_match('/\.(svg|ico|gif|bmp)$/i', $url->path)) {
            return true;
        }
        if ((null !== $width && $width < self::MIN_WIDTH) || (null !== $height && $height < self::MIN_HEIGHT)) {
            return true;
        }

        $pattern = '/(?:^|[\/_.\-\s])'.self::NOISE.'(?:[\/_.\-\s0-9]|$)/i';

        return 1 === preg_match($pattern, $url->path.' '.$hint) || (null !== $alt && 1 === preg_match('/^\s*'.self::NOISE.'\b/i', $alt));
    }

    private function hint(\DOMElement $element): string
    {
        return $element->getAttribute('class').' '.$element->getAttribute('id');
    }

    private function pixels(string $value): ?int
    {
        return 1 === preg_match('/^\s*(\d{1,5})(?:px)?\s*$/', $value, $match) && (int) $match[1] <= self::MAX_PIXELS ? (int) $match[1] : null;
    }

    /** The heading before the image, or its figure's caption: the nearest text that says what the picture shows. */
    private function context(\DOMElement $element): ?string
    {
        $document = $element->ownerDocument;
        if (null === $document) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        foreach (['ancestor::figure[1]//figcaption[1]', 'preceding::*[self::h1 or self::h2 or self::h3 or self::h4][1]'] as $query) {
            $found = $xpath->query($query, $element);
            $node = false !== $found ? $found->item(0) : null;
            $text = $node instanceof \DOMElement ? PlainText::clean($node->textContent, 200) : '';
            if ('' !== $text) {
                return $text;
            }
        }

        return null;
    }
}
