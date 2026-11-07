<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\RightsPolicyVersion;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A policy version row (G1). The migration owns `legacy`, which grants made by factories and the
 * demo story use: `RightsPolicyVersionFactory::legacy()` finds that row.
 *
 * @extends PersistentObjectFactory<RightsPolicyVersion>
 */
final class RightsPolicyVersionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return RightsPolicyVersion::class;
    }

    public static function legacy(): RightsPolicyVersion
    {
        return self::findOrCreate(['version' => 'legacy']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'version' => self::faker()->unique()->regexify('[a-f0-9]{12}'),
            'bodyMarkdown' => self::faker()->sentence(12),
            'at' => new \DateTimeImmutable(),
        ];
    }
}
