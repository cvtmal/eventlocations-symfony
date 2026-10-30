<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

use App\Admin\ValueObject\AdminRole;
use Doctrine\DBAL\Connection;

/**
 * The users screen, the audit filter and the console. Never selects the password hash or the TOTP secret.
 */
final class AdminUserListQuery
{
    private const string COLUMNS = <<<'SQL'
        SELECT id, display_name, email, role, totp_secret_encrypted IS NOT NULL AS totp_enrolled, is_active, last_login_at
        FROM admin_users
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<AdminUserRow>
     */
    public function all(): array
    {
        return array_map(self::row(...), $this->connection->fetchAllAssociative(self::COLUMNS.' ORDER BY display_name, id'));
    }

    public function find(string $id): ?AdminUserRow
    {
        $row = $this->connection->fetchAssociative(self::COLUMNS.' WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::row($row);
    }

    public function idByEmail(string $email): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM admin_users WHERE email = :email', ['email' => mb_strtolower($email)]);

        return false === $id ? null : (string) $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): AdminUserRow
    {
        return new AdminUserRow(
            (string) $row['id'],
            (string) $row['display_name'],
            (string) $row['email'],
            AdminRole::from((string) $row['role']),
            (bool) $row['totp_enrolled'],
            (bool) $row['is_active'],
            null === $row['last_login_at'] ? null : new \DateTimeImmutable((string) $row['last_login_at']),
        );
    }
}
