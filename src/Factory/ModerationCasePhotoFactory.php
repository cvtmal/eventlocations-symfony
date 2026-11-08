<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\ModerationCasePhoto;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A photo a complaint names (G2). Pass `case` and `photo`; while the case is open, the photo is hidden.
 *
 * @extends PersistentObjectFactory<ModerationCasePhoto>
 */
final class ModerationCasePhotoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ModerationCasePhoto::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'case' => ModerationCaseFactory::new(),
            'photo' => VenuePhotoFactory::new(),
            'at' => new \DateTimeImmutable(),
        ];
    }
}
