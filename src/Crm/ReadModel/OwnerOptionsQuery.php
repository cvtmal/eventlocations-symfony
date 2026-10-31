<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The staff accounts for the owner selects (one statement). Deactivated accounts are listed too: a
 * filter must find what they still own, and an inline select must show a deactivated current owner;
 * `selectable()` keeps only what a new choice may be. The statement also carries the sidebar's `TaskBadge`, because
 * every page with an owner select renders the sidebar right after it.
 */
final class OwnerOptionsQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TaskBadge $badge,
    ) {
    }

    /**
     * @return list<OwnerOption>
     */
    public function all(): array
    {
        [$dueTasks, $parameters] = $this->badge->column();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name, is_active, '.$dueTasks.' AS due_tasks FROM admin_users ORDER BY lower(display_name), id',
            $parameters,
        );

        $dueCount = $rows[0]['due_tasks'] ?? null;
        $this->badge->remember(null === $dueCount ? null : (int) $dueCount);

        return array_map(
            static fn (array $row): OwnerOption => new OwnerOption((string) $row['id'], (string) $row['display_name'], (bool) $row['is_active']),
            $rows,
        );
    }

    /**
     * The active accounts, plus the current owner when it is deactivated, so the select can show it.
     *
     * @param list<OwnerOption> $owners
     *
     * @return list<OwnerOption>
     */
    public static function selectable(array $owners, ?string $currentId = null): array
    {
        return array_values(array_filter($owners, static fn (OwnerOption $owner): bool => $owner->active || $owner->id === $currentId));
    }
}
