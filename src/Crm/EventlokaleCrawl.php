<?php

declare(strict_types=1);

namespace App\Crm;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Fills the EventlokaleCache (#49): the term groups, every page of the wedding search, then one
 * detail per listed venue, one request at a time with a pause in between. A rerun fetches only
 * what is missing, so a stopped or killed run resumes where it ended.
 *
 * A 429 that survives the scoped client's own retries counts toward a streak; any answer resets
 * it, and MAX_CONSECUTIVE_429 in a row stop the run. A 429 on a list page or the terms retries
 * that request; on a detail it moves on and leaves the venue for the next run.
 */
final class EventlokaleCrawl
{
    public const int MAX_CONSECUTIVE_429 = 3;

    /** Requests made in the current run; the first one goes out without a pause. */
    private int $requests = 0;
    private int $streak = 0;

    public function __construct(
        private readonly EventlokaleClient $client,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param int|null                                    $limit      fetch at most this many details in this run
     * @param \Closure(int $done, int $toFetch):void|null $onProgress called after each detail attempt
     */
    public function run(string $root, int $delayMs, int $jitterMs, ?int $limit, bool $refresh, bool $listOnly, ?\Closure $onProgress = null): EventlokaleCrawlResult
    {
        $this->requests = 0;
        $this->streak = 0;
        $cache = new EventlokaleCache($root);
        $pace = static fn (): int => $delayMs + random_int(0, $jitterMs);

        try {
            if ($refresh || !$cache->hasTaxonomies()) {
                $cache->writeTaxonomies($this->sendUntilAnswered(fn (): array => $this->client->taxonomies(), $pace));
            }
            if ($refresh || !$cache->hasIndex()) {
                $this->fetchIndex($cache, $pace);
            }
        } catch (ExceptionInterface $exception) {
            return $this->result($cache, 0, 0, 0, self::isRateLimit($exception) ? 0 : 1, self::isRateLimit($exception));
        }

        $ids = array_values(array_filter(array_map(
            static fn (array $item): ?int => \is_int($item['id'] ?? null) ? $item['id'] : null,
            $cache->readIndex()['items'],
        ), \is_int(...)));
        $pending = $refresh ? $ids : array_values(array_filter($ids, static fn (int $id): bool => !$cache->isSettled($id)));
        $skipped = \count($ids) - \count($pending);
        if ($listOnly) {
            return $this->result($cache, 0, $skipped, 0, 0, false);
        }
        if (null !== $limit) {
            $pending = \array_slice($pending, 0, $limit);
        }

        $fetched = $gone = $failed = $done = 0;
        foreach ($pending as $id) {
            try {
                $detail = $this->send(fn (): ?array => $this->client->detail($id), $pace);
                if (null === $detail) {
                    $cache->markGone($id);
                    ++$gone;
                } else {
                    $cache->writeDetail($id, $detail);
                    ++$fetched;
                }
            } catch (ExceptionInterface $exception) {
                if (!self::isRateLimit($exception)) {
                    ++$failed;
                } elseif ($this->streak >= self::MAX_CONSECUTIVE_429) {
                    $onProgress?->__invoke(++$done, \count($pending));

                    return $this->result($cache, $fetched, $skipped, $gone, $failed, true);
                }
            }
            $onProgress?->__invoke(++$done, \count($pending));
        }

        return $this->result($cache, $fetched, $skipped, $gone, $failed, false);
    }

    /**
     * @param \Closure():int $pace
     *
     * @throws ExceptionInterface
     */
    private function fetchIndex(EventlokaleCache $cache, \Closure $pace): void
    {
        $now = $this->clock->now();
        $items = [];
        $total = 0;
        $lastPage = 1;
        for ($page = 1; $page <= $lastPage; ++$page) {
            $body = $this->sendUntilAnswered(fn (): array => $this->client->search($page, $now), $pace);
            $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
            if (1 === $page) {
                $total = \is_int($data['total'] ?? null) ? $data['total'] : 0;
                $lastPage = \is_int($data['last_page'] ?? null) ? $data['last_page'] : 1;
            }
            foreach (\is_array($data['data'] ?? null) ? $data['data'] : [] as $item) {
                if (\is_array($item) && \is_int($item['id'] ?? null)) {
                    $items[$item['id']] ??= $item;
                }
            }
        }
        $cache->writeIndex(array_values($items), $total, $now);
    }

    /**
     * Repeats a request that got a 429 until it is answered or the streak stops the run.
     *
     * @template T
     *
     * @param \Closure():T   $request
     * @param \Closure():int $pace
     *
     * @return T
     *
     * @throws ExceptionInterface
     */
    private function sendUntilAnswered(\Closure $request, \Closure $pace): mixed
    {
        while (true) {
            try {
                return $this->send($request, $pace);
            } catch (ExceptionInterface $exception) {
                if (!self::isRateLimit($exception) || $this->streak >= self::MAX_CONSECUTIVE_429) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * One request, after the pause unless it is the run's first.
     *
     * @template T
     *
     * @param \Closure():T   $request
     * @param \Closure():int $pace
     *
     * @return T
     *
     * @throws ExceptionInterface
     */
    private function send(\Closure $request, \Closure $pace): mixed
    {
        if ($this->requests++ > 0) {
            $this->clock->sleep($pace() / 1000);
        }
        try {
            $result = $request();
        } catch (ExceptionInterface $exception) {
            if (self::isRateLimit($exception)) {
                ++$this->streak;
            }

            throw $exception;
        }
        $this->streak = 0;

        return $result;
    }

    private static function isRateLimit(ExceptionInterface $exception): bool
    {
        return $exception instanceof HttpExceptionInterface && 429 === $exception->getResponse()->getStatusCode();
    }

    private function result(EventlokaleCache $cache, int $fetched, int $skipped, int $gone, int $failed, bool $rateLimited): EventlokaleCrawlResult
    {
        $index = $cache->hasIndex() ? $cache->readIndex() : ['total' => 0, 'items' => []];

        return new EventlokaleCrawlResult($index['total'], \count($index['items']), $fetched, $skipped, $gone, $failed, $rateLimited);
    }
}
