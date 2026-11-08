<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\EraseModerationComplainants;
use App\Venues\Repository\ModerationCaseRepository;
use Psr\Clock\ClockInterface;

final class EraseModerationComplainantsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ModerationCaseRepository $cases,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(EraseModerationComplainants $command): int
    {
        $now = $this->clock->now();
        // One day early: the job runs once a night, so the last night before the three years are up erases what
        // would otherwise outlive them by up to a day.
        $due = $this->cases->complainantsDueForErasure(
            $now->modify(\sprintf('-%d years +1 day', EraseModerationComplainants::RETENTION_YEARS)),
        );

        if (!$command->dryRun) {
            foreach ($due as $case) {
                $case->eraseComplainant($now);
            }
        }

        return \count($due);
    }
}
