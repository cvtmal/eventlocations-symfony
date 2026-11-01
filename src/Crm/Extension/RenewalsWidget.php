<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Crm\ReadModel\RenewalsQuery;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Verlängerungen" (CRM-8, `Main.dc.html`): the contracts ending soonest. A row links to its deal, which the design
 * leaves out, because "Verlängern" is there.
 */
final readonly class RenewalsWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(private RenewalsQuery $renewals)
    {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('renewal.widget.title', [], 'crm');
    }

    public function template(): string
    {
        return 'admin/crm/widgets/_renewals.html.twig';
    }

    public function context(): array
    {
        return ['preview' => $this->renewals->upcoming(self::LIMIT)];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    /**
     * The design's order is Anfragen, Aufgaben, Verlängerungen, Unvollständige: below #27's 300, above #24's 200 (D23).
     */
    public function priority(): int
    {
        return 250;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
