<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Http;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\FetchLimits;
use App\Shared\Http\FetchPolicy;
use App\Shared\Http\RefusalReason;
use App\Shared\Http\SafeFetcher;
use App\Tests\Support\Http\StubHostResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;

/**
 * SafeFetcher over the real cURL transport, against a throw-away `php -S` server on 127.0.0.1 (the only place a
 * private address is allowed: FetchPolicy::unsafeForLocalFixtures() and a resolver that maps a made-up name to it).
 * The mocked tests pin which options are passed; this one proves cURL honours them: the name is reached only through
 * the pin, the client does not follow a redirect, an endless, a silent and a slow-dripping server are cut off, and an
 * environment proxy is ignored.
 */
final class SafeFetcherTransportTest extends TestCase
{
    private static int $port;
    private static string $log;

    /** @var resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::$log = sys_get_temp_dir().'/fetcher-transport-'.self::$port.'.log';

        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.self::$port, \dirname(__DIR__, 3).'/Support/Http/fixture-server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '6', 'FIXTURE_LOG' => self::$log] + getenv(),
        );
        self::assertIsResource($server);
        self::$server = $server;

        for ($try = 0; $try < 60; ++$try) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.2);
            if (false !== $connection) {
                fclose($connection);

                return;
            }
            usleep(100000);
        }
        self::fail('The fixture server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    private function fetcher(?FetchLimits $limits = null): SafeFetcher
    {
        return new SafeFetcher(
            HttpClient::create(),
            (new StubHostResolver())->host('fixtures.example', ['127.0.0.1']),
            new NativeClock(),
            'transport-test',
            $limits ?? new FetchLimits(minDelaySeconds: 0.0),
            FetchPolicy::unsafeForLocalFixtures(),
        );
    }

    private function url(string $path): string
    {
        return 'http://fixtures.example:'.self::$port.$path;
    }

    /**
     * @return list<string> the paths the server was asked for, in order
     */
    private function hits(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents(self::$log))));
    }

    private function assertRefusedWithin(RefusalReason $reason, float $seconds, string $path, ?FetchLimits $limits = null): FetchRefused
    {
        $start = microtime(true);
        try {
            $this->fetcher($limits)->open()->get($this->url($path));
            self::fail('Expected a refusal.');
        } catch (FetchRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
            self::assertLessThan($seconds, microtime(true) - $start, 'The refusal came too late.');

            return $e;
        }
    }

    public function test_the_name_is_reached_only_through_the_pin_and_the_request_carries_only_our_name(): void
    {
        $body = $this->fetcher()->open()->get($this->url('/'))->body;

        self::assertSame('<html><title>host=fixtures.example:'.self::$port.' ua=transport-test cookie=- auth=- encoding=identity</title></html>', $body);
    }

    public function test_the_client_does_not_follow_a_redirect_and_a_hop_to_a_private_address_is_never_requested(): void
    {
        $this->assertRefusedWithin(RefusalReason::BlockedAddress, 5.0, '/redirect-private');

        self::assertSame(['/robots.txt', '/redirect-private'], $this->hits());
    }

    public function test_a_redirect_is_followed_by_us_one_request_at_a_time(): void
    {
        $result = $this->fetcher()->open()->get($this->url('/redirect'));

        self::assertSame('/target', $result->url->path);
        self::assertSame(['/robots.txt', '/redirect', '/target'], $this->hits());
    }

    public function test_an_endless_body_is_cut_off_at_the_cap(): void
    {
        $this->assertRefusedWithin(RefusalReason::TooLarge, 6.0, '/forever', new FetchLimits(maxBytesPerResponse: 200_000, minDelaySeconds: 0.0));
    }

    public function test_a_server_that_goes_silent_is_given_up_on(): void
    {
        $this->assertRefusedWithin(RefusalReason::Transport, 4.0, '/idle', new FetchLimits(idleTimeoutSeconds: 1.0, minDelaySeconds: 0.0));
    }

    public function test_a_server_that_drips_is_cut_off_by_the_time_one_request_may_take(): void
    {
        $this->assertRefusedWithin(RefusalReason::Transport, 5.0, '/drip', new FetchLimits(idleTimeoutSeconds: 5.0, requestSeconds: 1.5, minDelaySeconds: 0.0));
    }

    public function test_an_announced_size_over_the_cap_is_refused_before_the_body_is_read(): void
    {
        $refusal = $this->assertRefusedWithin(RefusalReason::TooLarge, 4.0, '/huge-length', new FetchLimits(maxBytesPerResponse: 100_000, minDelaySeconds: 0.0));

        self::assertStringContainsString('announces 900000000 bytes', $refusal->getMessage());
    }

    public function test_something_that_is_not_a_page_is_refused(): void
    {
        $this->assertRefusedWithin(RefusalReason::ContentType, 4.0, '/image');
    }

    public function test_a_proxy_in_the_environment_is_ignored(): void
    {
        $saved = [$_SERVER['http_proxy'] ?? null, $_SERVER['HTTP_PROXY'] ?? null];
        $_SERVER['http_proxy'] = $_SERVER['HTTP_PROXY'] = 'http://127.0.0.1:9';
        try {
            self::assertStringContainsString('host=fixtures.example', $this->fetcher()->open()->get($this->url('/'))->body);
        } finally {
            foreach (['http_proxy', 'HTTP_PROXY'] as $i => $key) {
                null === $saved[$i] ? $_SERVER[$key] = null : $_SERVER[$key] = $saved[$i];
                if (null === $saved[$i]) {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}
