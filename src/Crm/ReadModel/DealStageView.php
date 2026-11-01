<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A column of the pipeline. Won and lost have left the open pipeline.
 */
final readonly class DealStageView
{
    public function __construct(
        public string $code,
        public bool $isWon,
        public bool $isLost,
    ) {
    }

    public function isClosed(): bool
    {
        return $this->isWon || $this->isLost;
    }
}
