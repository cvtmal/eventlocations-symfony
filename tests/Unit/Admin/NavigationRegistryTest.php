<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationRegistry;
use App\Admin\Extension\NavigationSection;
use App\Admin\ValueObject\NavigationItemView;
use App\Admin\ValueObject\NavigationSectionView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class NavigationRegistryTest extends TestCase
{
    public function test_sections_follow_the_enum_and_items_the_priority(): void
    {
        $registry = new NavigationRegistry([
            self::item('settings', NavigationSection::Settings),
            self::item('low', NavigationSection::Cms, priority: 1),
            self::item('high', NavigationSection::Cms, priority: 9),
            self::item('overview', NavigationSection::Overview),
        ], self::granting('ROLE_EDITOR'));

        $sections = $registry->sections(null);

        self::assertSame([NavigationSection::Overview, NavigationSection::Cms, NavigationSection::Settings], array_map(static fn (NavigationSectionView $section): NavigationSection => $section->section, $sections));
        self::assertSame(['high', 'low'], array_map(static fn (NavigationItemView $item): string => $item->route, $sections[1]->items));
    }

    public function test_equal_priorities_keep_their_order(): void
    {
        $registry = new NavigationRegistry([self::item('a', NavigationSection::Cms), self::item('b', NavigationSection::Cms)], self::granting('ROLE_EDITOR'));

        self::assertSame(['a', 'b'], array_map(static fn (NavigationItemView $item): string => $item->route, $registry->sections(null)[0]->items));
    }

    public function test_items_above_the_role_and_then_empty_sections_disappear(): void
    {
        $registry = new NavigationRegistry([
            self::item('mine', NavigationSection::Overview),
            self::item('theirs', NavigationSection::Settings, role: 'ROLE_ADMIN'),
        ], self::granting('ROLE_EDITOR'));

        $sections = $registry->sections(null);

        self::assertCount(1, $sections);
        self::assertSame(NavigationSection::Overview, $sections[0]->section);
    }

    public function test_the_active_item_matches_its_route_and_the_routes_below_it(): void
    {
        $registry = new NavigationRegistry([self::item('admin_users', NavigationSection::Settings), self::item('admin_audit_log', NavigationSection::Settings)], self::granting('ROLE_EDITOR'));

        $active = static fn (?string $route): array => array_map(static fn (NavigationItemView $item): bool => $item->active, $registry->sections($route)[0]->items);

        self::assertSame([true, false], $active('admin_users'));
        self::assertSame([true, false], $active('admin_users_edit'));
        self::assertSame([false, true], $active('admin_audit_log'));
        self::assertSame([false, false], $active('admin_usersx'));
        self::assertSame([false, false], $active(null));
    }

    private static function granting(string $role): AuthorizationCheckerInterface
    {
        return new class($role) implements AuthorizationCheckerInterface {
            public function __construct(private readonly string $role)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                return $attribute === $this->role;
            }
        };
    }

    private static function item(string $route, NavigationSection $section, int $priority = 0, string $role = 'ROLE_EDITOR'): NavigationItem
    {
        return new class($route, $section, $priority, $role) implements NavigationItem {
            public function __construct(
                private readonly string $route,
                private readonly NavigationSection $section,
                private readonly int $priority,
                private readonly string $role,
            ) {
            }

            public function section(): NavigationSection
            {
                return $this->section;
            }

            public function label(): TranslatableInterface
            {
                return new TranslatableMessage('x');
            }

            public function icon(): string
            {
                return 'x';
            }

            public function route(): string
            {
                return $this->route;
            }

            public function routeParameters(): array
            {
                return [];
            }

            public function badge(): ?int
            {
                return null;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function requiredRole(): string
            {
                return $this->role;
            }
        };
    }
}
