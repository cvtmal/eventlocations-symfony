<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A side panel on a record page, e.g. CRM's panel on a venue: Cms never sees Crm, the panel finds its place.
 */
#[AutoconfigureTag('admin.record_panel')]
interface RecordPanel
{
    /**
     * `venue`, `company`, `contact` or `deal`.
     */
    public function recordType(): string;

    public function template(): string;

    /**
     * @return array<string, mixed>
     */
    public function context(string $recordId): array;

    /**
     * Higher comes first.
     */
    public function priority(): int;

    public function requiredRole(): string;
}
