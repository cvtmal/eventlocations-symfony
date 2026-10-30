<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\TaxonomyTerm;
use App\Venues\Entity\VenueTerm;
use App\Venues\ValueObject\TermGroup;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;
use function Zenstruck\Foundry\Persistence\repository;

/**
 * Terms are the rows the migration seeds, so there is no TaxonomyTerm factory: the term is
 * looked up (a random one by default, or term()).
 *
 * @extends PersistentObjectFactory<VenueTerm>
 */
final class VenueTermFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueTerm::class;
    }

    public function term(TermGroup $group, string $code): self
    {
        return $this->with(['term' => lazy(static fn (): object => repository(TaxonomyTerm::class)->findOrFail(['termGroup' => $group, 'code' => $code]))]);
    }

    public function main(): self
    {
        return $this->with(['isMain' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'term' => lazy(static fn (): object => repository(TaxonomyTerm::class)->random()),
            'isMain' => false,
        ];
    }
}
