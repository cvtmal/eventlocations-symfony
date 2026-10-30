<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use App\Admin\ValueObject\RecordPanelView;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RecordPanelRegistry
{
    /**
     * @param iterable<RecordPanel> $panels
     */
    public function __construct(
        #[AutowireIterator('admin.record_panel')]
        private readonly iterable $panels,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<RecordPanelView>
     */
    public function for(string $recordType, string $recordId): array
    {
        $views = [];
        foreach (PriorityOrder::visible($this->panels, $this->authorization) as $panel) {
            if ($panel->recordType() === $recordType) {
                $views[] = new RecordPanelView($panel->template(), $panel->context($recordId));
            }
        }

        return $views;
    }
}
