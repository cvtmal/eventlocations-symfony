<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * #20: what production runs with is a promise the dev and test environments never exercise, so
 * the files themselves are pinned, without a kernel.
 */
final class ProductionConfigTest extends TestCase
{
    public function test_production_answers_only_for_its_own_hosts(): void
    {
        $framework = self::whenProd('framework.yaml')['framework'];
        self::assertIsArray($framework);
        $hosts = $framework['trusted_hosts'];
        self::assertIsArray($hosts);

        self::assertSame(['^deine-eventlocation\.ch$', '^www\.deine-eventlocation\.ch$', '^deineeventlocation\.ch$'], $hosts);

        foreach (['deine-eventlocation.ch' => true, 'www.deine-eventlocation.ch' => true, 'deineeventlocation.ch' => true, 'www.deineeventlocation.ch' => false, 'deine-eventlocation.ch.evil.example' => false, 'evildeine-eventlocation.ch' => false] as $host => $trusted) {
            $matches = array_filter($hosts, static fn (mixed $pattern): bool => 1 === preg_match('{'.$pattern.'}', $host));
            self::assertSame($trusted, [] !== $matches, $host);
        }
    }

    public function test_proxy_headers_are_named_and_no_proxy_is_hardcoded(): void
    {
        $framework = self::whenProd('framework.yaml')['framework'];
        self::assertIsArray($framework);

        self::assertSame(['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port'], $framework['trusted_headers']);
        // PD3: SYMFONY_TRUSTED_PROXIES decides, and only if the probe shows a proxy.
        self::assertArrayNotHasKey('trusted_proxies', $framework);
        self::assertArrayNotHasKey('trusted_proxies', self::config('framework.yaml')['framework'] ?? []);
    }

    public function test_the_cache_needs_no_fixed_prefix_seed(): void
    {
        // C2a: one app directory, so the default seed never changes.
        self::assertStringNotContainsString('prefix_seed', self::file('config/packages/framework.yaml'));
    }

    public function test_the_connect_timeout_lives_in_the_driver_options(): void
    {
        $doctrine = self::whenProd('doctrine.yaml')['doctrine'];
        self::assertIsArray($doctrine);
        $dbal = $doctrine['dbal'];
        self::assertIsArray($dbal);
        $options = $dbal['options'];
        self::assertIsArray($options);

        self::assertArrayHasKey(\PDO::ATTR_TIMEOUT, $options);
        self::assertSame(10, $options[\PDO::ATTR_TIMEOUT]);
    }

    public function test_dotenv_values_stay_out_of_the_process_environment(): void
    {
        // Would put every secret into the C environment of each child process (C12 revision).
        self::assertStringNotContainsString('use_putenv', self::file('composer.json'));
    }

    public function test_sentry_is_configured_for_production_only(): void
    {
        self::assertSame(['when@prod'], array_keys(self::config('sentry.yaml')));
    }

    public function test_sentry_sends_no_personal_data(): void
    {
        $options = self::sentryOptions();

        self::assertFalse($options['send_default_pii']);
        self::assertSame('none', $options['max_request_body_size']);
        // A policy of its own, which would override both lines above.
        self::assertArrayNotHasKey('data_collection', $options);
    }

    public function test_sentry_collects_errors_only(): void
    {
        $options = self::sentryOptions();
        $sentry = self::whenProd('sentry.yaml')['sentry'];
        self::assertIsArray($sentry);

        self::assertFalse($sentry['tracing']);
        self::assertSame(0.0, (float) ($options['traces_sample_rate'] ?? 0));
        self::assertArrayNotHasKey('traces_sampler', $options);
    }

    public function test_sentry_ignores_expected_client_errors_and_never_a_server_error(): void
    {
        $ignored = self::sentryOptions()['ignore_exceptions'];
        self::assertIsArray($ignored);

        self::assertContains(NotFoundHttpException::class, $ignored);
        self::assertContains(GoneHttpException::class, $ignored);
        self::assertContains(TooManyRequestsHttpException::class, $ignored);
        // Matched with is_a(): the parent would drop every 5xx as well.
        self::assertNotContains(HttpException::class, $ignored);

        foreach ($ignored as $class) {
            self::assertIsString($class);
            self::assertTrue(class_exists($class) || interface_exists($class), $class.' is a class that does not exist: Sentry would never match it.');
        }
    }

    public function test_the_sentry_bundle_loads_in_production_only(): void
    {
        /** @var array<class-string, array<string, bool>> $bundles */
        $bundles = require \dirname(__DIR__, 3).'/config/bundles.php';

        self::assertSame(['prod' => true], $bundles[SentryBundle::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sentryOptions(): array
    {
        $sentry = self::whenProd('sentry.yaml')['sentry'];
        self::assertIsArray($sentry);
        /** @var array<string, mixed> $options */
        $options = $sentry['options'];

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function whenProd(string $file): array
    {
        /** @var array<string, mixed> $block */
        $block = self::config($file)['when@prod'];

        return $block;
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(string $file): array
    {
        /** @var array<string, mixed> $config */
        $config = Yaml::parse(self::file('config/packages/'.$file), Yaml::PARSE_CONSTANT);

        return $config;
    }

    private static function file(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$name);
    }
}
