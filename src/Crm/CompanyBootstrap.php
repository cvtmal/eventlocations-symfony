<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\ReadModel\UnlinkedVenuesQuery;
use App\Crm\ValueObject\BootstrapOutcome;
use App\Crm\ValueObject\BootstrapReport;
use App\Shared\Uid\EntityId;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One run over the venues without a company (D19), for `app:crm:bootstrap` and the list's button:
 * one dispatch and transaction per venue, and a failing venue is reported and the run goes on. A
 * second run finds no venue, so it dispatches nothing and leaves no audit row.
 */
final readonly class CompanyBootstrap
{
    public function __construct(
        private UnlinkedVenuesQuery $venues,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function run(?int $limit = null): BootstrapReport
    {
        $companies = $contacts = $withoutContactPerson = $emailsTaken = $skipped = 0;
        $failures = [];

        foreach ($this->venues->numbers($limit) as $number => $venueId) {
            try {
                $outcome = $this->commandBus->dispatch(new CreateCompanyForVenue($venueId, EntityId::generate()))->last(HandledStamp::class)?->getResult();
                \assert($outcome instanceof BootstrapOutcome);
            } catch (\Exception $exception) {
                $cause = $exception instanceof HandlerFailedException ? (array_values($exception->getWrappedExceptions())[0] ?? $exception) : $exception;
                if ($cause instanceof \Error) {
                    throw $exception;
                }
                $failures[] = $number.': '.self::reason($cause);
                continue;
            }

            if ($outcome->isSkipped()) {
                ++$skipped;
                continue;
            }
            ++$companies;
            $outcome->contactCreated ? ++$contacts : ++$withoutContactPerson;
            $emailsTaken += $outcome->emailTaken ? 1 : 0;
        }

        return new BootstrapReport($companies, $contacts, $withoutContactPerson, $emailsTaken, $this->venues->sharingInquiryEmail(), $skipped, $failures, $this->venues->remaining());
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
