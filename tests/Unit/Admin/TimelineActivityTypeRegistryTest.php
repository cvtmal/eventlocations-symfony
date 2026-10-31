<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Extension\TimelineActivityType;
use App\Admin\Extension\TimelineActivityTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class TimelineActivityTypeRegistryTest extends TestCase
{
    public function test_types_follow_the_priority_and_the_role(): void
    {
        $registry = new TimelineActivityTypeRegistry(
            [self::type('low', 1), self::type('secret', 50, role: 'ROLE_ADMIN'), self::type('high', 9)],
            self::granting('ROLE_EDITOR'),
        );

        self::assertSame(['high', 'low'], array_map(static fn (TimelineActivityType $type): string => $type->code(), $registry->visible()));
    }

    public function test_a_type_only_the_system_writes_is_not_composable(): void
    {
        $registry = new TimelineActivityTypeRegistry([self::type('by_hand', 2), self::type('system', 5, composable: false)], self::granting('ROLE_EDITOR'));

        self::assertSame(['system', 'by_hand'], array_map(static fn (TimelineActivityType $type): string => $type->code(), $registry->visible()));
        self::assertSame(['by_hand'], array_map(static fn (TimelineActivityType $type): string => $type->code(), $registry->composable()));
    }

    public function test_composable_codes_follow_the_role(): void
    {
        $registry = new TimelineActivityTypeRegistry(
            [self::type('note', 2), self::type('admins_only', 1, 'ROLE_ADMIN'), self::type('system', 5, composable: false)],
            self::granting('ROLE_EDITOR'),
        );

        self::assertSame(['note'], $registry->composableCodes());
    }

    public function test_is_composable_asks_no_role_because_a_handler_has_no_session(): void
    {
        $registry = new TimelineActivityTypeRegistry(
            [self::type('note', 2, 'ROLE_ADMIN'), self::type('system', 5, composable: false)],
            self::granting('ROLE_EDITOR'),
        );

        self::assertTrue($registry->isComposable('note'));
        self::assertFalse($registry->isComposable('system'));
        self::assertFalse($registry->isComposable('unknown'));
    }

    private static function type(string $code, int $priority, string $role = 'ROLE_EDITOR', bool $composable = true): TimelineActivityType
    {
        return new class($code, $priority, $role, $composable) implements TimelineActivityType {
            public function __construct(private readonly string $code, private readonly int $priority, private readonly string $role, private readonly bool $composable)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function label(): TranslatableInterface
            {
                return new TranslatableMessage($this->code);
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function requiredRole(): string
            {
                return $this->role;
            }

            public function composable(): bool
            {
                return $this->composable;
            }
        };
    }

    private static function granting(string $role): AuthorizationCheckerInterface
    {
        return new class($role) implements AuthorizationCheckerInterface {
            public function __construct(private readonly string $role)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null): bool
            {
                return 'ROLE_EDITOR' === $attribute || $attribute === $this->role;
            }
        };
    }
}
