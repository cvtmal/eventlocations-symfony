<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use App\Admin\ValueObject\NavigationItemView;
use App\Admin\ValueObject\NavigationSectionView;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class NavigationRegistry
{
    /**
     * @param iterable<NavigationItem> $items
     */
    public function __construct(
        #[AutowireIterator('admin.navigation_item')]
        private readonly iterable $items,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * The sections the user sees, in design order; an empty one is dropped. An item is active on its own
     * route and on routes below it (`admin_users` on `admin_users_edit`).
     *
     * @return list<NavigationSectionView>
     */
    public function sections(?string $currentRoute): array
    {
        $visible = PriorityOrder::visible($this->items, $this->authorization);
        $sections = [];

        foreach (NavigationSection::cases() as $section) {
            $items = [];
            foreach ($visible as $item) {
                if ($item->section() === $section) {
                    $items[] = new NavigationItemView(
                        $item->label(),
                        $item->icon(),
                        $item->route(),
                        $item->routeParameters(),
                        $item->badge(),
                        null !== $currentRoute && ($currentRoute === $item->route() || str_starts_with($currentRoute, $item->route().'_')),
                    );
                }
            }

            if ([] !== $items) {
                $sections[] = new NavigationSectionView($section, $items);
            }
        }

        return $sections;
    }
}
