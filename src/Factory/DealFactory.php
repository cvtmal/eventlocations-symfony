<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Deal;
use App\Crm\Entity\DealStage;
use App\Crm\ValueObject\LostReason;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;
use function Zenstruck\Foundry\Persistence\repository;

/**
 * A new deal of a new company, without venue, amount, contract or owner. `inStage()` moves it, so tests name only
 * what they need; `DealStage` rows are migrated, never built here.
 *
 * @extends PersistentObjectFactory<Deal>
 */
final class DealFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Deal::class;
    }

    public function inStage(string $code, ?\DateTimeImmutable $at = null, ?LostReason $reason = null): self
    {
        return $this->afterInstantiate(static function (Deal $deal) use ($code, $at, $reason): void {
            $stage = self::stage($code);
            $deal->moveTo($stage, $reason ?? ($stage->isLost() ? LostReason::Other : null), $at ?? new \DateTimeImmutable());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'name' => self::faker()->randomElement(['Premium 2027', 'Top Premium 2027', 'Empfehlung 2027', 'Verlängerung 2027', 'Basic 2027']),
            'company' => lazy(static fn () => CompanyFactory::createOne()),
            'stage' => lazy(static fn () => self::stage(DealStage::NEW)),
        ];
    }

    private static function stage(string $code): DealStage
    {
        $stage = repository(DealStage::class)->find($code);

        return $stage instanceof DealStage ? $stage : throw new \LogicException(\sprintf('No deal stage "%s" is seeded.', $code));
    }
}
