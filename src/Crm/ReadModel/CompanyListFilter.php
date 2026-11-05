<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;

/**
 * What the company list narrows by (CRM-6, D20): `q` a name, `stage`, `owner` an admin user id or
 * `OWNER_NONE`, `venues` `VENUES_WITH` or `VENUES_WITHOUT` an Eintrag (the list's tabs, #56).
 */
final readonly class CompanyListFilter
{
    public const string OWNER_NONE = 'none';
    public const string VENUES_WITH = 'with';
    public const string VENUES_WITHOUT = 'without';

    public function __construct(
        public ?string $q = null,
        public ?CompanyStage $stage = null,
        public ?string $owner = null,
        public ?string $venues = null,
    ) {
        if (null !== $venues && !\in_array($venues, [self::VENUES_WITH, self::VENUES_WITHOUT], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown venue filter "%s".', $venues));
        }
    }
}
