<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

/**
 * What the near-me page's query string asked for (#52): a centre, or none with an optional
 * notice, and the trimmed `ort` to refill the field.
 */
final readonly class NearbyRequest
{
    public const string NOT_FOUND = 'not_found';
    public const string POSITION = 'position';

    /**
     * @param self::NOT_FOUND|self::POSITION|null $notice
     */
    public function __construct(
        public ?NearbyCentre $centre,
        public ?string $notice,
        public ?string $ort,
    ) {
    }
}
