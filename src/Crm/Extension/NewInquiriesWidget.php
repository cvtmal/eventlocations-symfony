<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Crm\ReadModel\InquiryInboxQuery;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Neue Anfragen" (`Main.dc.html`): the newest inquiries, whatever their status, as the design shows them.
 */
final readonly class NewInquiriesWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(private InquiryInboxQuery $inquiries)
    {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('inquiry.widget.title', [], 'crm');
    }

    public function template(): string
    {
        return 'admin/crm/widgets/_inquiries.html.twig';
    }

    public function context(): array
    {
        return ['rows' => $this->inquiries->newest(self::LIMIT)];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    /**
     * First on the dashboard, the design's order: above #27's Meine Aufgaben (400).
     */
    public function priority(): int
    {
        return 500;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
