<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\SafeUrl;
use App\Venues\Exception\UnreadablePage;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reads one HTML page of a venue's website into facts, image references and links worth following. It copies no
 * running text: headings and short snippets are facts for staff to read, kept at most a few hundred characters
 * each, and nothing of it reaches a public field (G3).
 */
final class SitePageParser
{
    private const int MAX_DEPTH = 300;

    public function __construct(
        private readonly JsonLdReader $jsonLd = new JsonLdReader(),
        private readonly ContactReader $contacts = new ContactReader(),
        private readonly ImageReader $images = new ImageReader(),
        private readonly LinkSelector $links = new LinkSelector(),
    ) {
    }

    /**
     * @throws UnreadablePage
     */
    public function parse(string $body, ?string $charset, SafeUrl $pageUrl, int $linkLimit = 10): ParsedPage
    {
        $html = self::toUtf8($body, $charset);
        self::assertNotTooDeep($html);

        // The body is UTF-8 from here on: handing it over as a string would let the Crawler read a leftover
        // <meta charset=iso-8859-1> and decode the text a second time.
        $crawler = new Crawler(null, (string) $pageUrl);
        $crawler->addHtmlContent($html, 'UTF-8');
        $source = (string) $pageUrl;
        $facts = new FactSet();
        $base = $this->baseUrl($crawler, $pageUrl);

        $lang = $crawler->filter('html[lang]');
        if ($lang->count() > 0 && 1 === preg_match('/^([a-z]{2})(?:[-_]|$)/i', $lang->attr('lang') ?? '', $match)) {
            $facts->add('language', strtolower($match[1]), 0.6, $source, 'html');
        }

        foreach (DomElements::of($crawler->filter('link[rel="canonical"][href]'), 3) as $canonical) {
            $this->addUrl($facts, 'canonical_url', $base, $canonical->getAttribute('href'), 0.9, $source, 'link');
        }

        $meta = [];
        foreach (DomElements::of($crawler->filter('meta[content]'), 300) as $tag) {
            $key = strtolower($tag->getAttribute('property') ?: $tag->getAttribute('name'));
            if ('' !== $key && !isset($meta[$key])) {
                $meta[$key] = trim($tag->getAttribute('content'));
            }
        }
        $facts->add('name', $meta['og:site_name'] ?? '', 0.8, $source, 'open_graph');
        $facts->add('name', $meta['og:title'] ?? '', 0.6, $source, 'open_graph');
        $facts->add('name', $meta['twitter:title'] ?? '', 0.5, $source, 'twitter_card');
        $facts->add('description', $meta['og:description'] ?? '', 0.6, $source, 'open_graph');
        $facts->add('description', $meta['description'] ?? '', 0.5, $source, 'meta');
        $facts->add('description', $meta['twitter:description'] ?? '', 0.4, $source, 'twitter_card');
        if (isset($meta['og:url'])) {
            $this->addUrl($facts, 'canonical_url', $base, $meta['og:url'], 0.7, $source, 'open_graph');
        }

        $title = $crawler->filter('head > title');
        if ($title->count() > 0) {
            $facts->add('name', $title->text(''), 0.4, $source, 'title');
        }
        $first = true;
        foreach (DomElements::of($crawler->filter('h1, h2, h3'), 300) as $heading) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $heading->textContent));
            if ('' === $text || mb_strlen($text) > 120) {
                continue;
            }
            if ($first && 'h1' === strtolower($heading->nodeName)) {
                $facts->add('name', $text, 0.4, $source, 'html');
                $first = false;
            }
            $facts->add('heading', $text, 0.5, $source, 'html');
        }

        $jsonLdImages = $this->jsonLd->read($crawler, $source, $facts);

        $lines = $crawler->filter('body')->count() > 0 ? PageText::lines($crawler->filter('body')->getNode(0) ?? new \DOMElement('body')) : [];
        $this->contacts->read($crawler, $lines, $source, $facts);
        $this->snippets($lines, $source, $facts);

        [$images, $skipped] = $this->images->read($crawler, $base, $pageUrl, $jsonLdImages);

        return new ParsedPage($facts, $images, $this->links->select($crawler, $base, $pageUrl, $linkLimit), $skipped);
    }

    /**
     * Building the DOM of deeply nested markup costs time that grows with the square of the depth (a megabyte of
     * `<div>` takes minutes), and no venue's page nests a few hundred levels. Elements that need an end tag are
     * counted by a linear scan, before the first DOM node exists.
     *
     * @throws UnreadablePage
     */
    private static function assertNotTooDeep(string $html): void
    {
        if (false === preg_match_all('~<(/?)(?:div|span|section|article|table|ul|ol|a|b|i|em|strong|font|center|blockquote|main|nav|header|footer|form)\b~i', $html, $tags)) {
            return;
        }

        $depth = 0;
        foreach ($tags[1] as $closing) {
            $depth = '' === $closing ? $depth + 1 : max(0, $depth - 1);
            if ($depth > self::MAX_DEPTH) {
                throw UnreadablePage::tooDeep(self::MAX_DEPTH);
            }
        }
    }

    /**
     * Sentences that state a capacity or an area: the facts staff look for first. Short ones only.
     *
     * @param list<string> $lines
     */
    private function snippets(array $lines, string $source, FactSet $facts): void
    {
        foreach ($lines as $line) {
            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                if (mb_strlen($sentence) <= 200 && 1 === preg_match('/\b\d{2,4}\s*(?:Personen|Gäste|Gästen|Pers\.|Sitzplätze|Sitzplätzen|Stehplätze|Plätze|Plätzen|m²|m2|qm|Quadratmeter)/iu', $sentence)) {
                    $facts->add('snippet', $sentence, 0.4, $source, 'text');
                }
            }
        }
    }

    private function addUrl(FactSet $facts, string $key, SafeUrl $base, string $reference, float $confidence, string $source, string $via): void
    {
        try {
            $facts->add($key, (string) $base->resolve($reference), $confidence, $source, $via);
        } catch (FetchRefused) {
            // not a usable URL: no fact
        }
    }

    private function baseUrl(Crawler $crawler, SafeUrl $pageUrl): SafeUrl
    {
        $base = $crawler->filter('base[href]');
        if ($base->count() > 0) {
            try {
                return $pageUrl->resolve($base->attr('href') ?? '');
            } catch (FetchRefused) {
                // keep the page's own URL
            }
        }

        return $pageUrl;
    }

    /**
     * The body as UTF-8: by the header's charset, else by a meta tag in the first 2 KB, else UTF-8 if it is valid
     * and Windows-1252 (old Swiss sites) if not.
     */
    public static function toUtf8(string $body, ?string $charset): string
    {
        $body = ltrim($body, "\xEF\xBB\xBF");
        if (null === $charset && 1 === preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9_\-]+)/i', substr($body, 0, 2048), $match)) {
            $charset = $match[1];
        }
        $charset = null === $charset ? (mb_check_encoding($body, 'UTF-8') ? 'UTF-8' : 'Windows-1252') : strtoupper($charset);

        if ('UTF-8' === $charset || 'UTF8' === $charset) {
            return mb_check_encoding($body, 'UTF-8') ? $body : self::convert($body, 'Windows-1252');
        }

        return self::convert($body, $charset);
    }

    private static function convert(string $body, string $from): string
    {
        try {
            $converted = mb_convert_encoding($body, 'UTF-8', $from);
        } catch (\ValueError) {
            $converted = mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        }

        return \is_string($converted) ? $converted : '';
    }
}
