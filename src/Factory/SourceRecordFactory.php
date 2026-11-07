<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\SourceRecord;
use App\Venues\ValueObject\SourceType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<SourceRecord>
 */
final class SourceRecordFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return SourceRecord::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => null,
            'sourceType' => SourceType::OfficialWebsite,
            'url' => 'https://www.beispiel-venue.example/',
            'facts' => [],
            'report' => [],
            'fetchedAt' => new \DateTimeImmutable('2026-11-01 09:00:00'),
        ];
    }
}
