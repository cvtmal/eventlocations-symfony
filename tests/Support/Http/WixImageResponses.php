<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The test environment's HTTP transport (framework.yaml, when@test: mock_response_factory),
 * under every client, so no test can reach the network (#32 PD10). A test registers
 * the responses for a media id; a URL nobody registered throws, which fails the test loudly.
 */
final class WixImageResponses
{
    private const string BASE = 'https://static.wixstatic.com/media/';

    /** @var array<string, list<MockResponse>> by URL; the last one answers every later request */
    private array $responses = [];

    /** @var list<string> */
    private array $requested = [];

    /**
     * Answers GET /media/<id> with an image's bytes and their Content-Length.
     */
    public function image(string $id, string $bytes): void
    {
        $this->respond($id, new MockResponse($bytes, ['response_headers' => ['content-type' => 'image/jpeg', 'content-length' => (string) \strlen($bytes)]]));
    }

    /**
     * Answers GET /media/<id> with these responses in turn.
     */
    public function respond(string $id, MockResponse ...$responses): void
    {
        $this->responses[self::BASE.rawurlencode($id)] = array_values($responses);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requested[] = $url;
        $queue = $this->responses[$url] ?? [];
        if ([] === $queue) {
            throw new \LogicException(\sprintf('No mock response for %s %s: tests never reach the network.', $method, $url));
        }
        if (\count($queue) > 1) {
            array_shift($this->responses[$url]);
        }

        return $queue[0];
    }

    /**
     * @return list<string> every URL requested, in order, retries included
     */
    public function requested(): array
    {
        return $this->requested;
    }
}
