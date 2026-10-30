<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One link in the sidebar. Implement it in a module's `Extension` layer; autoconfiguration registers it.
 */
#[AutoconfigureTag('admin.navigation_item')]
interface NavigationItem
{
    public function section(): NavigationSection;

    public function label(): TranslatableInterface;

    /**
     * A symbol of assets/icons/admin-sprite.svg.
     */
    public function icon(): string;

    public function route(): string;

    /**
     * @return array<string, scalar>
     */
    public function routeParameters(): array;

    /**
     * The count shown beside the label; null shows none.
     */
    public function badge(): ?int;

    /**
     * Higher comes first within the section.
     */
    public function priority(): int;

    public function requiredRole(): string;
}
