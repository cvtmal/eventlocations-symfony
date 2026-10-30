<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What every registry does with its extensions: keep those the user may see, highest priority first.
 * A stable sort, so equal priorities keep their registration order.
 */
final class PriorityOrder
{
    /**
     * @template T of NavigationItem|DashboardWidget|RecordTab|RecordPanel|SearchProvider
     *
     * @param iterable<T> $extensions
     *
     * @return list<T>
     */
    public static function visible(iterable $extensions, AuthorizationCheckerInterface $authorization): array
    {
        $visible = [];
        foreach ($extensions as $extension) {
            if ($authorization->isGranted($extension->requiredRole())) {
                $visible[] = $extension;
            }
        }

        usort($visible, static fn (object $a, object $b): int => $b->priority() <=> $a->priority());

        return $visible;
    }
}
