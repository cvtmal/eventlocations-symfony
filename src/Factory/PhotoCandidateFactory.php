<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\PhotoCandidate;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PhotoCandidate>
 */
final class PhotoCandidateFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PhotoCandidate::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'sourceRecord' => SourceRecordFactory::new(),
            'image' => new ImageCandidate('https://www.beispiel-venue.example/media/saal.jpg', 'https://www.beispiel-venue.example/', CandidateOrigin::Img),
            'position' => 1,
        ];
    }
}
