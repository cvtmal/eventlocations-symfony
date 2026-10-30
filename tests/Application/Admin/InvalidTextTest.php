<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * R2-m1: text Postgres cannot store as typed is a field error under /admin, never a truncated value or a
 * database error (`Admin\Form\AdminTextConstraintExtension`).
 */
final class InvalidTextTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFilters(): iterable
    {
        yield 'invalid UTF-8 in the subject id' => ['subject_id=%ff'];
        yield 'a NUL inside the subject id' => ['subject_id=a%00b'];
        yield 'invalid UTF-8 in the subject type' => ['subject_type=%ff'];
    }

    #[DataProvider('invalidFilters')]
    public function test_an_invalid_audit_filter_is_a_422_with_a_field_error(string $query): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/audit-log?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Der Text enthält ungültige Zeichen.', $crawler->filter('main')->text());
    }

    /**
     * `TrimListener` strips a NUL at either end, so a lone one is an empty filter, not an error.
     */
    public function test_a_lone_nul_is_trimmed_to_no_filter(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/audit-log?subject_id=%00');

        self::assertResponseIsSuccessful();
    }

    public function test_a_nul_in_a_display_name_creates_no_user_and_no_audit_row(): void
    {
        $this->logInAs();
        $auditRows = $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log');

        $this->client->request('GET', '/admin/benutzer/neu');
        $this->client->submitForm('Erfassen', [
            'create_admin_user[email]' => 'neu@example.ch',
            'create_admin_user[displayName]' => "Nora\0Neu",
            'create_admin_user[role]' => 'editor',
            'create_admin_user[plainPassword][first]' => 'a-long-enough-password',
            'create_admin_user[plainPassword][second]' => 'a-long-enough-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#create_admin_user_displayName_error', 'Der Text enthält ungültige Zeichen.');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_users WHERE email = 'neu@example.ch'"));
        self::assertSame($auditRows, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log'));
    }
}
