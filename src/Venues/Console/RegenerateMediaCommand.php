<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Media\ImageVariantGenerator;
use App\Venues\Command\RegenerateMedia;
use App\Venues\ValueObject\RegenerateMediaResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Re-renders the variants from the originals under MEDIA_ROOT (after a settings change or
 * a host move) and re-publishes the PDFs. The files keep their URLs, so `/media` must not be
 * cached as immutable across a regeneration (#19). Exits 1 if anything was skipped.
 *
 * It decodes the same originals as the import, so it raises memory_limit the same way
 * (ImageVariantGenerator::raiseBatchMemoryLimit(), #32 D9) and warns where that fails.
 */
#[AsCommand(name: 'app:media:regenerate', description: 'Re-render the image variants and re-publish the documents of all venues or one (--venue=<number>)')]
final class RegenerateMediaCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only the venue with this public number')]
        ?int $venue = null,
    ): int {
        if (!ImageVariantGenerator::raiseBatchMemoryLimit()) {
            $io->warning(ImageVariantGenerator::BATCH_MEMORY_WARNING);
        }
        $result = $this->commandBus->dispatch(new RegenerateMedia($venue))->last(HandledStamp::class)?->getResult();
        \assert($result instanceof RegenerateMediaResult);

        if ($result->venueMissing) {
            $io->error(\sprintf('No venue with number %d.', $venue));

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('Venues: %d. Photos: %d regenerated. Documents: %d published.', $result->venues, $result->photos, $result->documents));
        if ([] !== $result->failures) {
            $io->error(\sprintf('%d skipped:', \count($result->failures)));
            $io->listing($result->failures);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
