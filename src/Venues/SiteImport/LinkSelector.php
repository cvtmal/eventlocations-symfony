<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\SafeUrl;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Picks the pages of the same site that are worth a request: rooms, events, seminars, gallery, weddings, contact and
 * imprint. A link scores by the words in its path and in its text; the best few are returned, one per path.
 */
final class LinkSelector
{
    /** Pattern => score. Words are matched in the path and in the link text. */
    private const array WORDS = [
        '/event|veranstaltung|seminar|tagung|meeting|bankett|feier|fest|anlass|anl(ae|ä)sse|workshop|firmen/i' => 5,
        '/raum|räume|raeume|saal|säle|saele|rooms?\b|salle|location|lokal/i' => 5,
        '/galerie|gallery|bilder|fotos?\b|photos?\b|impressionen/i' => 4,
        '/hochzeit|wedding|mariage|trauung/i' => 3,
        '/kontakt|contact|anfahrt|impressum|imprint/i' => 3,
    ];
    private const string SKIP_EXTENSIONS = '/\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|pptx?|mp[34]|avi|ics)$/i';
    private const string SKIP_PATHS = '~^/(wp-admin|wp-json|feed|cart|warenkorb|login|search|suche)(/|$)~i';
    /** Language trees of a multilingual site; the one the page itself is in stays crawlable. */
    private const array LANGUAGES = ['en', 'fr', 'it', 'es', 'nl'];
    private const int MAX_ANCHORS = 1500;
    private const int MIN_SCORE = 3;

    /**
     * @return list<PageLink>
     */
    public function select(Crawler $page, SafeUrl $base, SafeUrl $pageUrl, int $limit): array
    {
        /** @var array<string, PageLink> $found */
        $found = [];
        $own = strtolower(explode('/', ltrim($pageUrl->path, '/'))[0]);
        foreach (DomElements::of($page->filter('a[href]'), self::MAX_ANCHORS) as $anchor) {
            try {
                $url = $base->resolve($anchor->getAttribute('href'));
            } catch (FetchRefused) {
                continue;
            }
            if ($url->siteKey() !== $pageUrl->siteKey() || 1 === preg_match(self::SKIP_EXTENSIONS, $url->path) || 1 === preg_match(self::SKIP_PATHS, $url->path)) {
                continue;
            }
            $language = strtolower(explode('/', ltrim($url->path, '/'))[0]);
            if (\in_array($language, self::LANGUAGES, true) && $language !== $own) {
                continue;
            }

            $path = '/'.trim(strtolower($url->path), '/');
            if ($path === '/'.trim(strtolower($pageUrl->path), '/')) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent));
            $score = 0;
            foreach (self::WORDS as $pattern => $weight) {
                $score += 1 === preg_match($pattern, rawurldecode($path)) ? $weight : (1 === preg_match($pattern, $text) ? $weight - 2 : 0);
            }
            if ($score < self::MIN_SCORE) {
                continue;
            }

            if (!isset($found[$path]) || $found[$path]->score < $score) {
                $found[$path] = new PageLink($url->withPath($url->path), mb_substr($text, 0, 80), $score);
            }
        }

        $links = array_values($found);
        usort($links, static fn (PageLink $a, PageLink $b): int => [$b->score, \strlen($a->url->path)] <=> [$a->score, \strlen($b->url->path)]);

        return \array_slice($links, 0, $limit);
    }
}
