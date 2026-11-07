<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Http\PlainText;

/**
 * What the importer read off a website, as observations: a key (`name`, `address`, `phone` ...), a value, how sure
 * the importer is (0 to 1), the page it came from and how it was found. Several observations of one key are normal
 * (the name in JSON-LD and in the title); staff and the claim flow pick. Two equal values are kept once with the
 * higher confidence. Nothing here is published, and no key is mapped to the event vocabulary (F3).
 *
 * @phpstan-type Value string|int|float|array<string, string|int|float|null>
 * @phpstan-type Observation array{value: Value, confidence: float, source_url: string, via: string}
 */
final class FactSet
{
    /** Per key; a key that is not listed keeps three. */
    private const array LIMITS = ['heading' => 30, 'snippet' => 12, 'page' => 12, 'type' => 6];
    private const int DEFAULT_LIMIT = 3;
    private const int MAX_TEXT = 600;
    /** Observations kept per key while collecting; a page that repeats one key thousands of times costs no more than this. */
    private const int COLLECT_LIMIT = 200;

    /** @var array<string, list<Observation>> */
    private array $observations = [];

    /** @var array<string, array<string, int>> key => value signature => position in[key] */
    private array $positions = [];

    /**
     * @param Value $value
     */
    public function add(string $key, string|int|float|array $value, float $confidence, string $sourceUrl, string $via): void
    {
        if (\is_string($value)) {
            $value = PlainText::clean($value, self::MAX_TEXT);
            if ('' === $value) {
                return;
            }
        } elseif (\is_array($value)) {
            $value = array_map(static fn (string|int|float|null $part): string|int|float|null => \is_string($part) ? PlainText::clean($part, 200) : $part, $value);
        } elseif (\is_float($value) && !is_finite($value)) {
            return;
        }

        $signature = \is_array($value) ? 'a:'.json_encode($value) : \gettype($value)[0].':'.$value;
        $index = $this->positions[$key][$signature] ?? null;
        $existing = null === $index ? null : ($this->observations[$key][$index] ?? null);
        if (null !== $index && null !== $existing) {
            if ($confidence > $existing['confidence']) {
                $this->observations[$key][$index] = ['value' => $value, 'confidence' => round($confidence, 2), 'source_url' => $sourceUrl, 'via' => $via];
            }

            return;
        }
        if (\count($this->observations[$key] ?? []) >= self::COLLECT_LIMIT) {
            return;
        }

        $this->positions[$key][$signature] = \count($this->observations[$key] ?? []);
        $this->observations[$key][] = ['value' => $value, 'confidence' => round($confidence, 2), 'source_url' => $sourceUrl, 'via' => $via];
    }

    public function merge(self $other): void
    {
        foreach ($other->observations as $key => $list) {
            foreach ($list as $observation) {
                $this->add($key, $observation['value'], $observation['confidence'], $observation['source_url'], $observation['via']);
            }
        }
    }

    /**
     * The facts as stored: per key the most confident observations first (at most the key's limit; order of
     * appearance breaks ties, so a heading list keeps the page's order).
     *
     * @return array<string, list<Observation>>
     */
    public function toArray(): array
    {
        $facts = [];
        foreach ($this->observations as $key => $list) {
            $order = array_keys($list);
            usort($order, static fn (int $a, int $b): int => [$list[$b]['confidence'], $a] <=> [$list[$a]['confidence'], $b]);
            $facts[$key] = array_map(static fn (int $position): array => $list[$position], \array_slice($order, 0, self::LIMITS[$key] ?? self::DEFAULT_LIMIT));
        }
        ksort($facts);

        return $facts;
    }

    public function isEmpty(): bool
    {
        return [] === $this->observations;
    }
}
