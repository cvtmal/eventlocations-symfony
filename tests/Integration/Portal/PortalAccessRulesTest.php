<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * No route lives under /konto/api/ in #42, and the router's 404 fires before the firewall, so HTTP cannot
 * prove the rule yet. The access map can.
 */
final class PortalAccessRulesTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function closedPaths(): iterable
    {
        yield 'the JSON area' => ['/konto/api/locations/1/photos'];
        yield 'the JSON area, encoded' => ['/konto/%61pi/x'];
        yield 'the account' => ['/konto'];
        yield 'any later page' => ['/konto/locations/1'];
    }

    #[DataProvider('closedPaths')]
    public function test_a_path_needs_a_manager(string $path): void
    {
        self::assertSame(['ROLE_PORTAL_USER'], $this->rolesFor($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openPaths(): iterable
    {
        yield 'login' => ['/konto/anmelden'];
        yield 'logout' => ['/konto/abmelden'];
        yield 'register' => ['/konto/registrieren'];
        yield 'forgotten password' => ['/konto/passwort-vergessen'];
        yield 'new password' => ['/konto/passwort-neu/token'];
    }

    #[DataProvider('openPaths')]
    public function test_a_path_is_public(string $path): void
    {
        self::assertSame(['PUBLIC_ACCESS'], $this->rolesFor($path));
    }

    /**
     * @return array<mixed>|null
     */
    private function rolesFor(string $path): ?array
    {
        $map = self::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $map);

        return $map->getPatterns(Request::create($path, 'POST'))[0];
    }
}
