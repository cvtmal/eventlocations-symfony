<?php

declare(strict_types=1);

namespace App\Crm\Console;

use App\Crm\Command\WithdrawConsent;
use App\Crm\Exception\NoConsentRecorded;
use App\Crm\ReadModel\ConsentQuery;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Venues\ReadModel\VenueKeyQuery;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A hochzeitslocation.ch customer withdraws the yes (F14, D29): `WithdrawConsent` deletes the venue (with a
 * tombstone, so the import never brings it back), the Location's contacts and copied contact fields, suppresses the
 * Location and notes the withdrawal; then this command deletes the venue's content folder, and the owner deletes the
 * row from the sheet. The Location, its name and its notes stay as the proof. A dry run says what would go.
 */
#[AsCommand(name: 'app:crm:withdraw-consent', description: 'Delete an entry imported by consent, and what it brought, after the customer withdrew')]
final class WithdrawConsentCommand
{
    public function __construct(
        private readonly VenueKeyQuery $venues,
        private readonly VenueCompanyQuery $companies,
        private readonly ConsentQuery $consents,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly Filesystem $filesystem,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The number of the venue')]
        int $venueNumber,
        #[Argument('The content root that holds venues/ (default: CONTENT_ROOT)')]
        ?string $path = null,
        #[Option('The day the customer withdrew, YYYY-MM-DD (default: today)')]
        ?string $on = null,
        #[Option('Say what would go; change nothing')]
        bool $dryRun = false,
    ): int {
        $venue = $this->venues->find($venueNumber);
        if (null === $venue) {
            $io->error(\sprintf('There is no venue %d.', $venueNumber));

            return Command::FAILURE;
        }
        $companyId = $this->companies->companyIdOf($venue->id);
        if (null === $companyId || !$this->consents->has($companyId)) {
            $io->error(\sprintf('Venue %d has no Location with a recorded consent: it did not come in by consent.', $venueNumber));

            return Command::FAILURE;
        }
        $folder = null === $venue->contentKey ? null : rtrim($path ?? $this->contentRoot, '/').'/venues/'.$venue->contentKey;

        if ($dryRun) {
            $io->writeln(\sprintf('Dry run: would delete venue %d%s, the contacts and contact fields of its Location, suppress the Location and note the withdrawal.', $venueNumber, null === $venue->contentKey ? '' : ' ('.$venue->contentKey.')'));
            if (null !== $folder && is_dir($folder)) {
                $io->writeln('Dry run: would delete the folder '.$folder);
            }

            return Command::SUCCESS;
        }

        try {
            $this->commandBus->dispatch(new WithdrawConsent($companyId, $venueNumber, $on ?? $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('Y-m-d')));
        } catch (HandlerFailedException $exception) {
            $cause = array_values($exception->getWrappedExceptions())[0] ?? $exception;
            if ($cause instanceof \Error) {
                throw $exception;
            }
            $io->error($cause instanceof NoConsentRecorded ? $cause->getMessage() : \sprintf('Venue %d: the withdrawal failed (%s); nothing was changed.', $venueNumber, (new \ReflectionClass($cause))->getShortName()));

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('Venue %d deleted, its Location suppressed and cleared; the consent and withdrawal notes stay.', $venueNumber));
        if (null !== $folder && is_dir($folder)) {
            $this->filesystem->remove($folder);
            $io->writeln('Deleted the folder '.$folder);
        }
        if (null !== $venue->contentKey) {
            $io->writeln(\sprintf('Delete the row of %s from your sheet.', $venue->contentKey));
        }

        return Command::SUCCESS;
    }
}
