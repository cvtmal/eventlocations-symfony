<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Portal\Security\DevAutoLoginAuthenticator;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DevAutoLoginTest extends WebTestCase
{
    use PortalSession;

    public function test_the_header_does_nothing_outside_dev(): void
    {
        $this->client->request('GET', '/konto', server: ['HTTP_X_PORTAL_DEV_LOGIN' => 'dev-lighthouse']);

        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_it_supports_only_dev_with_the_matching_header(): void
    {
        $matching = new Request();
        $matching->headers->set(DevAutoLoginAuthenticator::HEADER, 'token');
        $mismatching = new Request();
        $mismatching->headers->set(DevAutoLoginAuthenticator::HEADER, 'other');

        self::assertTrue((new DevAutoLoginAuthenticator('dev', 'token', 'a@b.ch'))->supports($matching));
        self::assertFalse((new DevAutoLoginAuthenticator('dev', 'token', 'a@b.ch'))->supports($mismatching));
        self::assertFalse((new DevAutoLoginAuthenticator('dev', 'token', 'a@b.ch'))->supports(new Request()));
        self::assertFalse((new DevAutoLoginAuthenticator('dev', '', 'a@b.ch'))->supports(new Request()));
        self::assertFalse((new DevAutoLoginAuthenticator('prod', 'token', 'a@b.ch'))->supports($matching));
        self::assertFalse((new DevAutoLoginAuthenticator('test', 'token', 'a@b.ch'))->supports($matching));
    }
}
