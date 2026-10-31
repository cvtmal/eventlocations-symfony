<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class TimelineActivityTypeRegistry
{
    /**
     * @param iterable<TimelineActivityType> $types
     */
    public function __construct(
        #[AutowireIterator('admin.timeline_activity_type')]
        private readonly iterable $types,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<TimelineActivityType>
     */
    public function visible(): array
    {
        return PriorityOrder::visible($this->types, $this->authorization);
    }

    /**
     * The kinds staff may write by hand, in the same order.
     *
     * @return list<TimelineActivityType>
     */
    public function composable(): array
    {
        return array_values(array_filter($this->visible(), static fn (TimelineActivityType $type): bool => $type->composable()));
    }

    /**
     * @return list<string>
     */
    public function composableCodes(): array
    {
        return array_map(static fn (TimelineActivityType $type): string => $type->code(), $this->composable());
    }

    /**
     * Whether a kind of entry is written by hand at all, whoever asks: a handler has no session, and the role
     * is the controller's to check. A code no module registers is not composable.
     */
    public function isComposable(string $code): bool
    {
        foreach ($this->types as $type) {
            if ($type->code() === $code) {
                return $type->composable();
            }
        }

        return false;
    }
}
