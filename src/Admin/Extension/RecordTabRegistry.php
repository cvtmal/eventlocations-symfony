<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use App\Admin\ValueObject\RecordTabView;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RecordTabRegistry
{
    /**
     * @param iterable<RecordTab> $tabs
     */
    public function __construct(
        #[AutowireIterator('admin.record_tab')]
        private readonly iterable $tabs,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<RecordTabView>
     */
    public function for(string $recordType, string $recordId): array
    {
        $views = [];
        foreach (PriorityOrder::visible($this->tabs, $this->authorization) as $tab) {
            if ($tab->recordType() === $recordType) {
                $views[] = new RecordTabView($tab->label(), $tab->route(), $tab->routeParameters($recordId));
            }
        }

        return $views;
    }
}
