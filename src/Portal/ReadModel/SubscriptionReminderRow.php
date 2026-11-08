<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * One reminder mail's facts (G9). `recipients` are the location's active managers, or its inquiry address when it has
 * none (then `name` is null and the mail greets without a name). `freeOfCharge` is a term granted without payment (a
 * pilot): its mail names no price and offers the paid renewal instead of announcing an invoice.
 */
final readonly class SubscriptionReminderRow
{
    /**
     * @param list<array{email: string, name: ?string}> $recipients
     */
    public function __construct(
        public string $subscriptionId,
        public string $venueName,
        public string $tierCode,
        public string $tierName,
        public int $priceRappen,
        public bool $vatLine,
        public \DateTimeImmutable $endsOn,
        public array $recipients,
        public bool $freeOfCharge = false,
    ) {
    }
}
