<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A card on the dashboard. `template` is included inside the card with `context()`'s variables.
 */
#[AutoconfigureTag('admin.dashboard_widget')]
interface DashboardWidget
{
    public function title(): TranslatableInterface;

    public function template(): string;

    /**
     * @return array<string, mixed>
     */
    public function context(): array;

    public function size(): WidgetSize;

    /**
     * Higher comes first.
     */
    public function priority(): int;

    public function requiredRole(): string;
}
