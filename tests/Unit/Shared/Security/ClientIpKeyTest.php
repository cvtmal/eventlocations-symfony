<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security;

use App\Shared\Security\ClientIpKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClientIpKeyTest extends TestCase
{
    private const SECRET = 'fictional-app-secret-for-this-test';

    public function test_it_is_the_hmac_of_the_address_with_the_app_secret(): void
    {
        self::assertSame(
            hash_hmac('sha256', '198.51.100.23', self::SECRET),
            (new ClientIpKey(self::SECRET))->of(self::from('198.51.100.23')),
        );
    }

    public function test_the_same_address_gives_the_same_key_and_another_address_another(): void
    {
        $key = new ClientIpKey(self::SECRET);

        self::assertSame($key->of(self::from('198.51.100.23')), $key->of(self::from('198.51.100.23')));
        self::assertNotSame($key->of(self::from('198.51.100.23')), $key->of(self::from('198.51.100.24')));
    }

    public function test_another_secret_gives_another_key(): void
    {
        self::assertNotSame(
            (new ClientIpKey(self::SECRET))->of(self::from('198.51.100.23')),
            (new ClientIpKey('another-fictional-secret'))->of(self::from('198.51.100.23')),
        );
    }

    public function test_a_request_without_an_address_still_has_a_key(): void
    {
        $request = Request::create('/');
        $request->server->remove('REMOTE_ADDR');

        self::assertSame(hash_hmac('sha256', 'unknown', self::SECRET), (new ClientIpKey(self::SECRET))->of($request));
    }

    private static function from(string $ip): Request
    {
        return Request::create('/', server: ['REMOTE_ADDR' => $ip]);
    }
}
