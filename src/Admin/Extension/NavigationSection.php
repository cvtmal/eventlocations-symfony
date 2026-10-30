<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * The sidebar's sections, in display order (design: Sidebar.dc.html).
 */
enum NavigationSection: string
{
    case Overview = 'overview';
    case Cms = 'cms';
    case Crm = 'crm';
    case Settings = 'settings';

    public function label(): TranslatableMessage
    {
        return new TranslatableMessage('nav.section.'.$this->value, [], 'admin');
    }

    /**
     * The first section stands on its own in the design, without a heading.
     */
    public function hasHeading(): bool
    {
        return self::Overview !== $this;
    }
}
