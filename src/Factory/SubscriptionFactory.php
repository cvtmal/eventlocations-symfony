<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\Subscription;
use App\Venues\Entity\Tier;
use App\Venues\ValueObject\KnownTiers;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * A paid term of G9 (`subscriptions`): Pro for one year from today by default. Writes the row only: keep
 * `venues.tier_id` in step yourself (VenueFactory does for a paid tier) or run `RefreshVenueSubscription`.
 * `cancelled()` records the manager's "do not renew".
 *
 * @extends PersistentObjectFactory<Subscription>
 */
final class SubscriptionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Subscription::class;
    }

    /**
     * A term granted free of charge (a pilot): amount 0 with its note.
     */
    public function freeOfCharge(string $note = 'Pilot'): self
    {
        return $this->with(['amountRappen' => 0, 'note' => $note]);
    }

    public function cancelled(): self
    {
        return $this->afterInstantiate(static function (Subscription $subscription): void {
            $subscription->requestCancellation(new \DateTimeImmutable('@'.time()));
        });
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $attributes): array {
            $tier = $attributes['tier'] ?? KnownTiers::PRO;
            if (\is_string($tier)) {
                $tier = repository(Tier::class)->findOneBy(['code' => $tier]) ?? throw new \LogicException(\sprintf('No tier "%s".', $tier));
            }
            $attributes['tier'] = $tier;
            $attributes['amountRappen'] ??= $tier->priceRappen();

            return $attributes;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'venue' => VenueFactory::new(),
            'startsOn' => $today,
            'endsOn' => $today->modify('+1 year')->modify('-1 day'),
            'invoiceRef' => null,
            'note' => null,
            'paidAt' => new \DateTimeImmutable('@'.time()),
        ];
    }
}
