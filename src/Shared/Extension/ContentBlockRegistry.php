<?php

declare(strict_types=1);

namespace App\Shared\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every declared ContentBlockDefinition, by key. Two definitions with one key are a
 * programming error and fail the container's first use of the registry.
 */
final class ContentBlockRegistry
{
    /** @var array<string, ContentBlockDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<ContentBlockDefinition> $definitions
     */
    public function __construct(
        #[AutowireIterator('content_block.definition')]
        iterable $definitions,
    ) {
        foreach ($definitions as $definition) {
            $key = $definition->key();
            if (isset($this->definitions[$key])) {
                throw new \LogicException(\sprintf('Two content blocks are declared with the key "%s": %s and %s.', $key, $this->definitions[$key]::class, $definition::class));
            }
            $this->definitions[$key] = $definition;
        }
        ksort($this->definitions, \SORT_STRING);
    }

    /**
     * @return list<ContentBlockDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $key): ?ContentBlockDefinition
    {
        return $this->definitions[$key] ?? null;
    }
}
