<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\Command\ChangeAdminRole;
use App\Admin\Command\DeactivateAdminUser;
use App\Admin\Command\ResetAdminPassword;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What an admin changes on a user's account takes effect on that user's next request.
 */
final class SessionEndsOnChangeTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    /**
     * @return iterable<string, array{\Closure(string): object}>
     */
    public static function changes(): iterable
    {
        yield 'deactivated' => [static fn (string $id): object => new DeactivateAdminUser($id, EntityId::generate())];
        yield 'password reset' => [static fn (string $id): object => new ResetAdminPassword($id, 'another-long-password')];
        yield 'role changed' => [static fn (string $id): object => new ChangeAdminRole($id, AdminRole::Admin, EntityId::generate())];
    }

    /**
     * @param \Closure(string): object $command
     */
    #[DataProvider('changes')]
    public function test_the_next_request_goes_to_the_login_page(\Closure $command): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->dispatch($command($staff['user']->id()));

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }
}
