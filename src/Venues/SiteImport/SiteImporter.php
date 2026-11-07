<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\FetchResult;
use App\Shared\Http\RefusalReason;
use App\Shared\Http\SafeFetcher;
use App\Shared\Http\SafeUrl;
use App\Venues\Exception\UnreadablePage;
use App\Venues\ValueObject\ImageCandidate;
use Symfony\Component\Clock\ClockInterface;

/**
 * Crawls a venue's own website for facts and image references (G3, D16): the entry page, then up to seven pages of the
 * same site that the entry page links to and that look like rooms, events, gallery or contact. Every request goes
 * through SafeFetcher. It writes nothing and maps nothing into the event vocabulary; `app:venues:import-site` stores
 * the result in `source_records` and `photo_candidates`, private tables no page reads.
 */
final class SiteImporter
{
    public const int MAX_PAGES = 8;
    public const int MAX_IMAGES = 60;

    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly SitePageParser $parser,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws FetchRefused   when the entry page itself cannot be fetched; a later page that is refused is in the result
     * @throws UnreadablePage when the entry page cannot be parsed; a later one that cannot is in the result
     */
    public function import(string $url): SiteImportResult
    {
        $url = (string) SafeUrl::parse($url);
        $session = $this->fetcher->open();
        $entry = $session->get($url);

        $facts = new FactSet();
        $candidates = [];
        $skipped = 0;
        $pages = [];
        $refused = [];

        $take = static function (FetchResult $result, ParsedPage $page) use ($facts, &$candidates, &$skipped, &$pages): void {
            $pages[] = ['url' => (string) $result->url, 'status' => $result->status, 'bytes' => \strlen($result->body)];
            $facts->merge($page->facts);
            $skipped += $page->skippedImages;
            $known = array_flip(array_map(static fn (ImageCandidate $candidate): string => ImageReader::identity($candidate->imageUrl), $candidates));
            foreach ($page->images as $image) {
                $identity = ImageReader::identity($image->imageUrl);
                if (\count($candidates) < self::MAX_IMAGES && !isset($known[$identity])) {
                    $candidates[] = $image;
                    $known[$identity] = true;
                }
            }
        };

        $first = $this->parser->parse($entry->body, $entry->charset, $entry->url, self::MAX_PAGES - 1);
        $take($entry, $first);
        foreach ($first->links as $link) {
            $facts->add('page', (string) $link->url, min(0.9, 0.3 + $link->score / 10), (string) $entry->url, 'link');
        }

        foreach ($first->links as $link) {
            try {
                $result = $session->get((string) $link->url);
            } catch (FetchRefused $e) {
                $refused[] = ['url' => (string) $link->url, 'reason' => $e->reason->value, 'detail' => $e->detail];
                if (\in_array($e->reason, [RefusalReason::PageLimit, RefusalReason::TimeLimit, RefusalReason::ByteBudget], true)) {
                    break;
                }
                continue;
            }
            try {
                $page = $this->parser->parse($result->body, $result->charset, $result->url, 0);
            } catch (UnreadablePage $e) {
                $refused[] = ['url' => (string) $result->url, 'reason' => 'unreadable', 'detail' => $e->getMessage()];
                continue;
            }
            $take($result, $page);
        }

        return new SiteImportResult(
            $url,
            (string) $entry->url,
            $facts->toArray(),
            $candidates,
            $skipped,
            $pages,
            $refused,
            $entry->redirects,
            $session->bytes(),
            $session->seconds(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }
}
