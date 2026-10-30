<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A tab on a record page (venue, company, contact, deal), added by the module that owns the tab's content.
 */
#[AutoconfigureTag('admin.record_tab')]
interface RecordTab
{
    /**
     * `venue`, `company`, `contact` or `deal`.
     */
    public function recordType(): string;

    public function label(): TranslatableInterface;

    public function route(): string;

    /**
     * @return array<string, scalar>
     */
    public function routeParameters(string $recordId): array;

    /**
     * Higher comes first.
     */
    public function priority(): int;

    public function requiredRole(): string;
}
