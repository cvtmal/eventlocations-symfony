<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\TermGroup;

/**
 * The term codes that exist per taxonomy group (rows of `taxonomy_terms`). The search codec
 * keeps a filter value only when it is a known code of its group, so an unknown code is
 * dropped instead of silently emptying an AND group. Load it with KnownTermsQuery; unit
 * tests build it by hand with of().
 */
final readonly class KnownTerms
{
    /**
     * @param array<string, list<string>> $codes TermGroup value → codes
     */
    private function __construct(
        private array $codes,
    ) {
    }

    /**
     * @param array<string, list<string>> $map TermGroup value → codes
     */
    public static function of(array $map): self
    {
        return new self($map);
    }

    /**
     * @param iterable<array<string, mixed>> $rows rows with `term_group` and `code`
     */
    public static function fromPairs(iterable $rows): self
    {
        $codes = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['term_group']));
            \assert(\is_string($row['code']));
            $codes[$row['term_group']][] = $row['code'];
        }

        return new self($codes);
    }

    public function has(TermGroup $group, string $code): bool
    {
        return \in_array($code, $this->codes[$group->value] ?? [], true);
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), $this->codes));
    }
}
