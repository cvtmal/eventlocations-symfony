<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Fixtures\Admin\AdminExtensionProbeKernel;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * `/admin/api/search` on the probe kernel: the fake module's provider echoes the query it receives.
 */
final class GlobalSearchTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    protected static function getKernelClass(): string
    {
        return AdminExtensionProbeKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new AdminExtensionProbeKernel('test', true);
    }

    public function test_an_editor_gets_grouped_results(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/api/search?q=see');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['groups' => [[
            'label' => 'Fake Treffer',
            'results' => [['type' => 'probe', 'label' => 'see', 'detail' => 'limit 5', 'url' => '/admin/_probe/treffer']],
        ]]], $this->answer());
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $this->client->request('GET', '/admin/api/search?q=see');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_provider_without_results_leaves_no_group(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/api/search?q=nichts');

        self::assertSame(['groups' => []], $this->answer());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableQueries(): iterable
    {
        yield 'none' => [''];
        yield 'one character' => ['?q=a'];
        yield 'blank' => ['?q=%20%20%20'];
        yield 'an array' => ['?q[]=x'];
        yield 'invalid UTF-8' => ['?q=%ff%ff'];
        yield 'a NUL' => ['?q=ab%00cd'];
    }

    #[DataProvider('unusableQueries')]
    public function test_an_unusable_query_finds_nothing_and_never_fails(string $query): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/api/search'.$query);

        self::assertResponseIsSuccessful();
        self::assertSame(['groups' => []], $this->answer());
    }

    public function test_a_long_query_reaches_the_provider_cut_to_100_characters(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/api/search?q='.str_repeat('ä', 150));

        self::assertSame(str_repeat('ä', 100), $this->answer()['groups'][0]['results'][0]['label']);
    }

    public function test_the_query_is_trimmed(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/api/search?q=%20see%20');

        self::assertSame('see', $this->answer()['groups'][0]['results'][0]['label']);
    }

    /**
     * @return array{groups: list<array{label: string, results: list<array{type: string, label: string, detail: ?string, url: string}>}>}
     */
    private function answer(): array
    {
        /** @var array{groups: list<array{label: string, results: list<array{type: string, label: string, detail: ?string, url: string}>}>} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer;
    }
}
