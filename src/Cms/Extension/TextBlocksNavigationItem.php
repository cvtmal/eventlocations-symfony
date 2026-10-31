<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Textbausteine" in the CMS section (#26). It stays active on `admin_text_blocks_edit` (the registry's
 * prefix rule).
 */
final class TextBlocksNavigationItem implements NavigationItem
{
    public function section(): NavigationSection
    {
        return NavigationSection::Cms;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.nav', [], 'cms');
    }

    public function icon(): string
    {
        return 'type';
    }

    public function route(): string
    {
        return 'admin_text_blocks';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function badge(): ?int
    {
        return null;
    }

    /**
     * Last in the CMS section, after Locations (300) and Landingpages (200).
     */
    public function priority(): int
    {
        return 100;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
