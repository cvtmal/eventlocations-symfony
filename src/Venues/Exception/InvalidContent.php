<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A content folder breaks the format (schema, rules, files); it carries every message found.
 */
final class InvalidContent extends DomainException
{
    /**
     * @param list<string> $messages
     */
    private function __construct(
        private readonly string $key,
        private readonly array $messages,
    ) {
        $first = $messages[0] ?? 'invalid content';
        parent::__construct(\sprintf('%s: %s%s', $key, $first, \count($messages) > 1 ? \sprintf(' (+%d more)', \count($messages) - 1) : ''));
    }

    /**
     * @param list<string> $messages at least one
     */
    public static function withMessages(string $key, array $messages): self
    {
        return new self($key, $messages);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
