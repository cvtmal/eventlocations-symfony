<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Extension\SearchProvider;
use App\Admin\Extension\SearchProviderRegistry;
use App\Admin\Extension\SearchResult;
use App\Admin\ValueObject\SearchGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class SearchProviderRegistryTest extends TestCase
{
    public function test_groups_follow_the_priority(): void
    {
        $registry = new SearchProviderRegistry([self::provider('low', 1), self::provider('high', 9)], self::granting('ROLE_EDITOR'));

        self::assertSame(['high', 'low'], self::labels($registry->search('see')));
    }

    public function test_a_provider_above_the_role_is_not_asked(): void
    {
        $theirs = self::provider('theirs', role: 'ROLE_ADMIN', onSearch: static fn () => self::fail('Not to be asked.'));
        $registry = new SearchProviderRegistry([self::provider('mine'), $theirs], self::granting('ROLE_EDITOR'));

        self::assertSame(['mine'], self::labels($registry->search('see')));
    }

    public function test_a_provider_gets_the_limit_and_cannot_exceed_it(): void
    {
        $limits = [];
        $provider = self::provider('many', results: 9, onSearch: static function (int $limit) use (&$limits): void {
            $limits[] = $limit;
        });

        $groups = (new SearchProviderRegistry([$provider], self::granting('ROLE_EDITOR')))->search('see');

        self::assertSame([5], $limits);
        self::assertCount(5, $groups[0]->results);
    }

    public function test_a_provider_without_results_has_no_group(): void
    {
        $registry = new SearchProviderRegistry([self::provider('empty', results: 0), self::provider('full')], self::granting('ROLE_EDITOR'));

        self::assertSame(['full'], self::labels($registry->search('see')));
    }

    /**
     * @param list<SearchGroup> $groups
     *
     * @return list<string>
     */
    private static function labels(array $groups): array
    {
        return array_map(static function (SearchGroup $group): string {
            \assert($group->label instanceof TranslatableMessage);

            return $group->label->getMessage();
        }, $groups);
    }

    /**
     * @param (\Closure(int): mixed)|null $onSearch
     */
    private static function provider(string $label, int $priority = 0, string $role = 'ROLE_EDITOR', int $results = 1, ?\Closure $onSearch = null): SearchProvider
    {
        return new class($label, $priority, $role, $results, $onSearch) implements SearchProvider {
            /**
             * @param (\Closure(int): mixed)|null $onSearch
             */
            public function __construct(
                private readonly string $labelKey,
                private readonly int $priorityValue,
                private readonly string $role,
                private readonly int $results,
                private readonly ?\Closure $onSearch,
            ) {
            }

            public function label(): TranslatableInterface
            {
                return new TranslatableMessage($this->labelKey);
            }

            public function search(string $q, int $limit): array
            {
                if (null !== $this->onSearch) {
                    ($this->onSearch)($limit);
                }

                $results = [];
                for ($i = 0; $i < $this->results; ++$i) {
                    $results[] = new SearchResult('probe', $q, null, '/admin/'.$i);
                }

                return $results;
            }

            public function priority(): int
            {
                return $this->priorityValue;
            }

            public function requiredRole(): string
            {
                return $this->role;
            }
        };
    }

    private static function granting(string ...$roles): AuthorizationCheckerInterface
    {
        return new class($roles) implements AuthorizationCheckerInterface {
            /**
             * @param array<string> $roles
             */
            public function __construct(
                private readonly array $roles,
            ) {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                return \in_array($attribute, $this->roles, true);
            }
        };
    }
}
