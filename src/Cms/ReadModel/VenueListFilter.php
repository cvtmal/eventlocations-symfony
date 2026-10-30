<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\Tier;

/**
 * What the venue list narrows by (CMS-1, D6). `q` is a name, a town, a venue number or a ZIP prefix;
 * `status` one of the `STATUS_*` values; `canton` a canton code or `CANTON_NONE` ("Ohne Kanton");
 * `withoutAddress` keeps the venues without a town ("Ohne Adresse").
 */
final readonly class VenueListFilter
{
    public const string STATUS_PUBLISHED = 'published';
    public const string STATUS_UNPUBLISHED = 'unpublished';
    public const string STATUS_INCOMPLETE = 'incomplete';
    public const array STATUSES = [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED, self::STATUS_INCOMPLETE];

    public const string CANTON_NONE = 'none';

    public function __construct(
        public ?string $q = null,
        public ?string $status = null,
        public ?Tier $tier = null,
        public ?string $canton = null,
        public bool $withoutAddress = false,
    ) {
        if (null !== $status && !\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown venue list status "%s".', $status));
        }
    }

    public function withStatus(?string $status): self
    {
        return new self($this->q, $status, $this->tier, $this->canton, $this->withoutAddress);
    }
}
