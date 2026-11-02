<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Tests\Support\Portal\PortalSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * S10: nothing under /konto is cached or indexed, whatever the response.
 */
final class PortalResponseHeadersTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    public function test_the_login_page(): void
    {
        $this->client->request('GET', '/konto/anmelden');

        self::assertResponseIsSuccessful();
        $this->assertNotCachedOrIndexed();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function test_the_account_page(): void
    {
        $this->logInAsManager();

        $this->client->request('GET', '/konto');

        self::assertResponseIsSuccessful();
        $this->assertNotCachedOrIndexed();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redirectingUrls(): iterable
    {
        yield 'plain' => ['/konto'];
        yield 'first letter encoded' => ['/%6Bonto'];
        yield 'every byte encoded' => ['/%6B%6F%6E%74%6F'];
    }

    #[DataProvider('redirectingUrls')]
    public function test_a_redirect(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/konto/anmelden');
        $this->assertNotCachedOrIndexed($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingUrls(): iterable
    {
        yield 'plain' => ['/konto/gibt-es-nicht'];
        yield 'first letter encoded' => ['/%6Bonto/gibt-es-nicht'];
        yield 'slash encoded' => ['/konto%2Fgibt-es-nicht'];
    }

    #[DataProvider('missingUrls')]
    public function test_a_404(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
        $this->assertNotCachedOrIndexed($url);
    }

    private function assertNotCachedOrIndexed(string $message = ''): void
    {
        $headers = $this->client->getResponse()->headers;

        self::assertTrue($headers->hasCacheControlDirective('no-store'), trim($message.' has no-store.'));
        self::assertTrue($headers->hasCacheControlDirective('private'), trim($message.' is private.'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'), $message);
    }
}
