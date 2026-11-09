<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Media\ImageVariantGenerator;
use App\Venues\Command\ImportVenueContent;
use App\Venues\Command\RecordContentImport;
use App\Venues\Command\UnpublishMissingContent;
use App\Venues\ContentFormat;
use App\Venues\ContentReader;
use App\Venues\ValueObject\ImportStatus;
use App\Venues\ValueObject\PruneOutcome;
use App\Venues\ValueObject\VenueImportOutcome;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Imports the venue content folders (`<path>/venues/<key>/`, docs/content-format.md) into
 * the venues (S8, #9). The CMS (#24) writes the same venues: a venue it edited since its last
 * import, or deleted, is kept as it is and listed after the summary (also under --force and
 * --prune) until --overwrite-cms-edits replaces it.
 *
 * Idempotent: an unchanged folder is skipped by its content hash, so a second run writes
 * nothing. One transaction per venue: a failing folder is reported and the run goes on;
 * the exit code is 1 if any folder failed. A run that is not a dry run is recorded in
 * content_imports; the handlers purge the HTTP cache of what changed (venue pages after each
 * venue's commit, the listings once per run).
 *
 * Run one import at a time: there is no lock (the database's unique keys prevent
 * corruption, not a confusing interleaving).
 *
 * memory_limit is raised to ImageVariantGenerator::BATCH_MEMORY_LIMIT first, because the
 * owner's photos reach 60 MP (#32 D9); where that fails the command warns and goes on.
 */
#[AsCommand(name: 'app:venues:import', description: 'Import the venue content folders (validate, create/update by key, photos, documents)')]
final class ImportContentCommand
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ContentReader $reader,
        private readonly ClockInterface $clock,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The content root that holds venues/ (default: CONTENT_ROOT)')]
        ?string $path = null,
        #[Option('Validate and report what would change; write nothing')]
        bool $dryRun = false,
        #[Option('Import only the folder with this key')]
        ?string $only = null,
        #[Option('Unpublish content venues whose folder is gone')]
        bool $prune = false,
        #[Option('Apply every folder, even when its content is unchanged (never over a CMS edit)')]
        bool $force = false,
        #[Option('Also apply folders of venues edited in the CMS since their last import, and recreate deleted venues')]
        bool $overwriteCmsEdits = false,
    ): int {
        if (!ImageVariantGenerator::raiseBatchMemoryLimit()) {
            $io->warning(ImageVariantGenerator::BATCH_MEMORY_WARNING);
        }
        $startedAt = $this->clock->now();
        $root = rtrim($path ?? $this->contentRoot, '/');
        if (null !== $only && $prune) {
            $io->error('--prune needs the whole content set; it cannot be combined with --only.');

            return Command::FAILURE;
        }
        if (null !== $only && !ContentFormat::isKey($only)) {
            $io->error(\sprintf('"%s" is not a venue key (lower-case letters, digits and single hyphens).', $only));

            return Command::FAILURE;
        }
        if (!is_dir($root.'/venues')) {
            $io->error(\sprintf('"%s" has no venues/ directory.', $root));

            return Command::FAILURE;
        }
        $root = realpath($root) ?: $root;

        ['keys' => $keys, 'warnings' => $warnings] = $this->reader->keys($root);
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        $presentKeys = $keys;
        if (null !== $only) {
            if (!\in_array($only, $keys, true)) {
                $io->error(\sprintf('There is no folder venues/%s in "%s".', $only, $root));

                return Command::FAILURE;
            }
            $keys = [$only];
        }

        $counts = array_fill_keys(['created', 'updated', 'unchanged', 'failed', 'cms_edited', 'photosAdded', 'photosRemoved', 'documentsAdded', 'documentsRemoved'], 0);
        $errors = [];
        $cmsEditedKeys = [];
        $drafts = [];
        $notes = [];
        foreach ($keys as $key) {
            $outcome = $this->import(new ImportVenueContent($root.'/venues/'.$key, $key, $dryRun, $force, $overwriteCmsEdits));
            ++$counts[$outcome->status->value];
            $counts['photosAdded'] += $outcome->photosAdded;
            $counts['photosRemoved'] += $outcome->photosRemoved;
            $counts['documentsAdded'] += $outcome->documentsAdded;
            $counts['documentsRemoved'] += $outcome->documentsRemoved;
            if (ImportStatus::Failed === $outcome->status) {
                $errors[] = ['key' => $key, 'messages' => $outcome->messages];
            } else {
                foreach ($outcome->messages as $message) {
                    if (str_starts_with($message, VenueImportOutcome::DRAFT_NOTE)) {
                        $drafts[] = $key.': '.substr($message, \strlen(VenueImportOutcome::DRAFT_NOTE));
                    } else {
                        $notes[] = $key.': '.$message;
                    }
                }
            }
            if (ImportStatus::CmsEdited === $outcome->status) {
                $cmsEditedKeys[] = $key;
            }
            if (ImportStatus::Unchanged !== $outcome->status || $io->isVerbose()) {
                $io->writeln(\sprintf('%-9s  %s', $outcome->status->value, $key));
            }
        }

        $unpublished = 0;
        if ($prune) {
            $result = $this->commandBus->dispatch(new UnpublishMissingContent($presentKeys, $dryRun, $overwriteCmsEdits))->last(HandledStamp::class)?->getResult();
            \assert($result instanceof PruneOutcome);
            $unpublished = $result->unpublished;
            $cmsEditedKeys = [...$cmsEditedKeys, ...$result->cmsEditedKeys];
        }

        if (!$dryRun) {
            $this->commandBus->dispatch(new RecordContentImport(
                $root,
                $only,
                $prune,
                $force,
                $overwriteCmsEdits,
                \count($keys),
                $counts['created'],
                $counts['updated'],
                $counts['unchanged'],
                $counts['failed'],
                $unpublished,
                \count($cmsEditedKeys),
                $counts['photosAdded'],
                $counts['photosRemoved'],
                $counts['documentsAdded'],
                $counts['documentsRemoved'],
                $errors,
                $startedAt,
                $this->clock->now(),
            ));
        }

        $io->writeln(\sprintf(
            '%sVenues: %d seen, %d created, %d updated, %d unchanged, %d failed, %d unpublished. Photos: +%d −%d. Documents: +%d −%d. Held as draft: %d.',
            $dryRun ? 'Dry run: ' : '',
            \count($keys),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['failed'],
            $unpublished,
            $counts['photosAdded'],
            $counts['photosRemoved'],
            $counts['documentsAdded'],
            $counts['documentsRemoved'],
            \count($drafts),
        ));

        // Notes never change the exit code: a draft and a long event-type list are work for staff, not errors (F14).
        if ([] !== $drafts) {
            $io->writeln('Held as draft (an entry goes online only with an inquiry address; one nobody answers for also needs checked facts and a description):');
            $io->listing($drafts);
        }
        if ([] !== $notes) {
            $io->writeln('Notes:');
            $io->listing($notes);
        }

        if ([] !== $cmsEditedKeys) {
            $io->writeln(\sprintf(
                '%d venue%s kept because the CMS edited them; --overwrite-cms-edits replaces them:',
                \count($cmsEditedKeys),
                1 === \count($cmsEditedKeys) ? '' : 's',
            ));
            $io->listing($cmsEditedKeys);
        }

        if ([] !== $errors) {
            $io->error(\sprintf('%d venue folder%s failed:', \count($errors), 1 === \count($errors) ? '' : 's'));
            $lines = [];
            foreach ($errors as $error) {
                foreach ($error['messages'] as $message) {
                    $lines[] = $error['key'].': '.$message;
                }
            }
            $io->listing($lines);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Dispatches one folder; a failure of its transaction becomes a failed outcome so the
     * run goes on. Errors (\Error: programming mistakes) still stop the run.
     */
    private function import(ImportVenueContent $command): VenueImportOutcome
    {
        $key = $command->key;
        $dryRun = $command->dryRun;
        try {
            $outcome = $this->commandBus->dispatch($command)->last(HandledStamp::class)?->getResult();
            \assert($outcome instanceof VenueImportOutcome);

            return $outcome;
        } catch (HandlerFailedException $exception) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof \Error) {
                    throw $exception;
                }
            }

            return VenueImportOutcome::failed($key, $dryRun, array_values(array_map(
                static fn (\Throwable $wrapped): string => $wrapped->getMessage(),
                $exception->getWrappedExceptions(),
            )));
        } catch (\Exception $exception) {
            return VenueImportOutcome::failed($key, $dryRun, [$exception->getMessage()]);
        }
    }
}
