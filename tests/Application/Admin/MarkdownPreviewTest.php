<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class MarkdownPreviewTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string URL = '/admin/api/markdown-preview';

    public function test_it_renders_and_neutralises_a_script(): void
    {
        $this->logInAs();

        $this->preview((string) json_encode(['text' => "**fett**\n\n<script>alert(1)</script>"]), $this->token());

        self::assertResponseIsSuccessful();
        $html = $this->html();
        self::assertStringContainsString('<strong>fett</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_an_empty_text_is_fine(): void
    {
        $this->logInAs();

        $this->preview('{"text": ""}', $this->token());

        self::assertResponseIsSuccessful();
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->logInAs();

        $this->preview('{"text": "x"}', null);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $this->preview('{"text": "x"}', 'any');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_text_above_the_limit_is_a_422(): void
    {
        $this->logInAs();

        $this->preview((string) json_encode(['text' => str_repeat('x', 20001)]), $this->token());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('[text]', $this->answer()['errors']);
    }

    public function test_malformed_json_is_a_422_not_an_html_400(): void
    {
        $this->logInAs();

        $this->preview('{nope', $this->token());

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_text_that_is_not_a_string_is_a_422(): void
    {
        $this->logInAs();

        $this->preview('{"text": ["x"]}', $this->token());

        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_null_text_is_a_422_not_a_500(): void
    {
        $this->logInAs();

        $this->preview('{"text": null}', $this->token());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('[text]', $this->answer()['errors']);
    }

    private function preview(string $body, ?string $token): void
    {
        $this->client->request('POST', self::URL, server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]), content: $body);
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function answer(): array
    {
        /** @var array<string, array<string, mixed>> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer;
    }

    private function html(): string
    {
        /** @var array{html: string} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer['html'];
    }
}
