<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\SuppressEmail;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Repository\SuppressionRepository;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * Adds one address, canonical (lower-case), to the list; an address already there keeps its first row.
 */
final class SuppressEmailHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly SuppressionRepository $suppressions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SuppressEmail $command): bool
    {
        $email = ContactEmail::normalise($command->email) ?? throw InvalidPropertyValue::required('email');

        return $this->suppressions->addEmail($email, SuppressionReason::Objection, $this->clock->now());
    }
}
