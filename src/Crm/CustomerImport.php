<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Command\ImportCustomer;
use App\Crm\Exception\InvalidCustomerSheet;
use App\Crm\ReadModel\CustomerMatchIndex;
use App\Crm\ReadModel\CustomerMatchQuery;
use App\Crm\ValueObject\CustomerImportOutcome;
use App\Crm\ValueObject\CustomerImportReport;
use App\Crm\ValueObject\CustomerRowClass;
use App\Shared\Uid\EntityId;
use App\Venues\ContentYamlFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One run over the owner's customer sheet (#48), for `app:crm:import-customers`. Every row is read and
 * classified before the first write, so a sheet that cannot be read changes nothing. A real run dispatches
 * one `ImportCustomer` per new customer, one transaction and one audit row each; a failing customer is
 * reported and the run goes on, as in `CompanyBootstrap`. Matched rows are never dispatched (P1), so a
 * second run creates nothing. The report file is written on a dry run too.
 */
final readonly class CustomerImport
{
    public const string REPORT_FILE = 'owner/kunden-import-report.md';

    public function __construct(
        private CustomerMatchQuery $query,
        private CustomerClassifier $classifier,
        private MessageBusInterface $commandBus,
        private EntityManagerInterface $em,
        #[Autowire('%app.content_root%')]
        private string $contentRoot,
    ) {
    }

    /**
     * @throws InvalidCustomerSheet
     */
    public function run(string $path, bool $dryRun): CustomerImportReport
    {
        $rows = iterator_to_array(CustomerSheet::rows($path), false);
        $keys = $this->query->fold(CustomerClassifier::keyCandidates($rows));
        $index = new CustomerMatchIndex($this->query->venues(), $this->query->companies(), $this->query->contactEmails(), $this->query->similar(CustomerClassifier::similarNames($rows)));
        $classifications = $this->classifier->classify($rows, $index, $keys);

        $created = $contacts = $activities = $skippedAtWrite = 0;
        $failures = [];
        foreach ($classifications as $classification) {
            if (CustomerRowClass::New !== $classification->class || null === $classification->record) {
                continue;
            }
            $record = $classification->record;
            if ($dryRun) {
                ++$created;
                ++$contacts;
                $activities += \count($record->mailDates);
                continue;
            }

            try {
                $outcome = $this->commandBus->dispatch(new ImportCustomer(EntityId::generate(), $record))->last(HandledStamp::class)?->getResult();
                \assert($outcome instanceof CustomerImportOutcome);
            } catch (\Exception $exception) {
                $cause = $exception instanceof HandlerFailedException ? (array_values($exception->getWrappedExceptions())[0] ?? $exception) : $exception;
                if ($cause instanceof \Error) {
                    throw $exception;
                }
                $failures[] = \sprintf('row %d: %s', $classification->row(), self::reason($cause));
                continue;
            } finally {
                // About 6,000 entities over a run would otherwise stay in the identity map.
                $this->em->clear();
            }

            if ($outcome->isSkipped()) {
                ++$skippedAtWrite;
                continue;
            }
            ++$created;
            ++$contacts;
            $activities += $outcome->activities;
        }

        $report = new CustomerImportReport($dryRun, \count($rows), $classifications, $created, $contacts, $activities, $skippedAtWrite, $failures);
        ContentYamlFile::replace($this->reportFile(), $report->markdown());

        return $report;
    }

    public function reportFile(): string
    {
        return $this->contentRoot.'/'.self::REPORT_FILE;
    }

    /**
     * A driver's message quotes the failing row, contact values included, so only the constraint's
     * name, else the exception's class, leaves this method.
     */
    private static function reason(\Throwable $cause): string
    {
        if (1 === preg_match('/constraint "([a-z0-9_]+)"/', $cause->getMessage(), $match)) {
            return $match[1];
        }

        return (new \ReflectionClass($cause))->getShortName();
    }
}
