<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Shared\Exception\FetchRefused;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\RecordSiteImport;
use App\Venues\Exception\UnreadablePage;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\SiteImport\SiteImporter;
use App\Venues\SiteImport\SiteImportResult;
use App\Venues\ValueObject\SourceType;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * G3, D16: reads a venue's own website through SafeFetcher (http and https only, public addresses only, robots.txt,
 * a pause between requests, byte, page and time caps) and stores the facts and the photo references it finds in the
 * private tables `source_records` and `photo_candidates`. It publishes nothing, copies no text into a public field,
 * downloads no image and creates no venue. `--venue` attaches the record to an existing venue by slug.
 * The report says what was found and which URLs were refused and why.
 */
#[AsCommand(name: 'app:venues:import-site', description: 'Read a venue\'s own website for facts and photo candidates into private tables (G3)')]
final class ImportSiteCommand
{
    public function __construct(
        private readonly SiteImporter $importer,
        private readonly VenueDetailQuery $venues,
        private readonly EnabledLocale $locales,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The address of the venue\'s website, http or https')]
        string $url,
        #[Option('Attach the import to the venue with this slug')]
        ?string $venue = null,
    ): int {
        $venueId = null;
        if (null !== $venue) {
            $match = $this->venues->resolveSlug($venue, $this->locales->default());
            if (null === $match) {
                $io->error(\sprintf('No venue with the slug "%s".', $venue));

                return Command::FAILURE;
            }
            $venueId = $match->venueId;
        }

        try {
            $result = $this->importer->import($url);
        } catch (FetchRefused $refusal) {
            $io->error(OutputFormatter::escape(\sprintf('Refused: %s (%s)', $refusal->detail, $refusal->reason->value)));

            return Command::FAILURE;
        } catch (UnreadablePage $unreadable) {
            $io->error(OutputFormatter::escape('Not read: '.$unreadable->getMessage()));

            return Command::FAILURE;
        }

        $recordId = $this->commandBus->dispatch(new RecordSiteImport(
            $result->url,
            $venueId,
            SourceType::OfficialWebsite,
            $result->facts,
            $result->report(),
            $result->candidates,
            $result->fetchedAt,
        ))->last(HandledStamp::class)?->getResult();
        \assert(\is_string($recordId));

        $this->report($io, $result);
        $io->success(\sprintf('Stored as source record %s%s.', $recordId, null === $venue ? ' (no venue)' : ' for '.$venue));

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, SiteImportResult $result): void
    {
        $io->writeln(\sprintf(
            '%s%s: %d page(s), %d KB, %.0f s.',
            OutputFormatter::escape($result->url),
            $result->finalUrl === $result->url ? '' : ' -> '.OutputFormatter::escape($result->finalUrl),
            \count($result->pages),
            (int) round($result->bytes / 1024),
            $result->seconds,
        ));

        $io->section('Facts (best value per key; count of observations)');
        foreach ($result->facts as $key => $observations) {
            $best = $observations[0];
            $value = \is_array($best['value']) ? implode(', ', array_filter($best['value'], static fn (mixed $part): bool => null !== $part && '' !== $part)) : (string) $best['value'];
            $io->writeln(\sprintf('  %-14s %2d  %s  (%.2f, %s)', $key, \count($observations), OutputFormatter::escape(mb_strimwidth($value, 0, 90, '...')), $best['confidence'], $best['via']));
        }

        $io->section('Photo candidates');
        $io->writeln(\sprintf('  %d found, %d left out as icons, logos or tiny pictures. Nothing was downloaded.', \count($result->candidates), $result->skippedImages));

        if ([] !== $result->refused) {
            $io->section('Refused');
            foreach ($result->refused as $refused) {
                $io->writeln(OutputFormatter::escape(\sprintf('  %s  [%s] %s', $refused['url'], $refused['reason'], $refused['detail'])));
            }
        }
    }
}
