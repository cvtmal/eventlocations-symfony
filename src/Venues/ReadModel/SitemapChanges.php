<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * The newest change among the published venues of each canton, town and building type (#17's
 * landing pages), keyed by canton code, town id and building code.
 */
final readonly class SitemapChanges
{
    /**
     * @param array<string, \DateTimeImmutable> $cantons
     * @param array<string, \DateTimeImmutable> $towns
     * @param array<string, \DateTimeImmutable> $buildings
     * @param array<string, \DateTimeImmutable> $events       by `suitable_for` code
     * @param array<string, \DateTimeImmutable> $eventCantons by `<suitable_for code>.<canton code>`
     */
    public function __construct(
        public array $cantons,
        public array $towns,
        public array $buildings,
        public array $events = [],
        public array $eventCantons = [],
    ) {
    }
}
