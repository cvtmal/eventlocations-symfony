<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Kernel;
use App\Shared\Security\ReviewMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * review-demo: APP_REVIEW_MODE skips 2FA and lets `admin` log in, so it boots only on the review stack
 * (compose.review.yaml): its database, Mailpit, no Google key, no Sentry, APP_ENV=prod.
 */
final class ReviewModeTest extends TestCase
{
    public function test_the_flag_off_has_no_problem_whatever_else_is_set(): void
    {
        self::assertSame([], ReviewMode::problems(['APP_REVIEW_MODE' => '0', 'DATABASE_URL' => 'postgresql://u:p@ep-x.neon.tech/site'], 'prod'));
        self::assertSame([], ReviewMode::problems(['DATABASE_URL' => 'postgresql://u:p@ep-x.neon.tech/site'], 'dev'));
    }

    public function test_the_review_stack_passes(): void
    {
        self::assertSame([], ReviewMode::problems(self::reviewEnv(), 'prod'));
        self::assertSame([], ReviewMode::problems(['MAILER_DSN' => 'null://null'] + self::reviewEnv(), 'prod'));
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('unsafeEnvironments')]
    public function test_an_unsafe_environment_is_refused_by_name_without_the_value(string $name, array $override, string $environment): void
    {
        $problems = ReviewMode::problems($override + self::reviewEnv(), $environment);

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertStringContainsString($name, $problems[0]);
        foreach ($override as $value) {
            if ('' !== $value && '1' !== $value && 'true' !== $value) {
                self::assertStringNotContainsString($value, $problems[0]);
            }
        }
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function unsafeEnvironments(): iterable
    {
        yield 'dev' => ['APP_ENV', [], 'dev'];
        yield 'test' => ['APP_ENV', [], 'test'];
        yield 'the dev database' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://app:app@database:5432/app?serverVersion=18'], 'prod'];
        yield 'Neon' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://site:secret-pw@ep-fake.eu-central-1.aws.neon.tech/review?sslmode=require'], 'prod'];
        yield 'another host named review' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://app:app@db.example.ch:5432/review'], 'prod'];
        yield 'no database' => ['DATABASE_URL', ['DATABASE_URL' => ''], 'prod'];
        yield 'a real mailer' => ['MAILER_DSN', ['MAILER_DSN' => 'smtp://user:secret-pw@mail.infomaniak.com:587'], 'prod'];
        yield 'Maps key' => ['GOOGLE_MAPS_JS_KEY', ['GOOGLE_MAPS_JS_KEY' => 'AIza-fake-key'], 'prod'];
        yield 'Embed key' => ['GOOGLE_MAPS_EMBED_KEY', ['GOOGLE_MAPS_EMBED_KEY' => 'AIza-fake-key'], 'prod'];
        yield 'Places key' => ['GOOGLE_PLACES_API_KEY', ['GOOGLE_PLACES_API_KEY' => 'AIza-fake-key'], 'prod'];
        yield 'Photos key' => ['GOOGLE_PLACES_PHOTOS_KEY', ['GOOGLE_PLACES_PHOTOS_KEY' => 'AIza-fake-key'], 'prod'];
        yield 'rating on' => ['GOOGLE_RATING_ENABLED', ['GOOGLE_RATING_ENABLED' => '1'], 'prod'];
        yield 'Sentry' => ['SENTRY_DSN', ['SENTRY_DSN' => 'https://key@o1.ingest.sentry.io/2'], 'prod'];
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function flagValues(): iterable
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'on' => ['on', true];
        yield '2' => ['2', true];
        yield '0' => ['0', false];
        yield 'empty' => ['', false];
        yield 'false' => ['false', false];
        yield 'off' => ['off', false];
        yield 'array' => [['1'], false];
    }

    #[DataProvider('flagValues')]
    public function test_the_flag_reads_like_symfony_s_bool_processor(mixed $value, bool $on): void
    {
        self::assertSame($on, ReviewMode::flagIsOn($value));
    }

    public function test_admin_stands_for_the_review_accounts_only_in_review_mode(): void
    {
        $on = new ReviewMode(true);
        $off = new ReviewMode(false);

        self::assertSame(ReviewMode::ADMIN_EMAIL, $on->adminIdentifier('admin'));
        self::assertSame(ReviewMode::MANAGER_EMAIL, $on->managerIdentifier('admin'));
        self::assertSame('admin', $off->adminIdentifier('admin'));
        self::assertSame('admin', $off->managerIdentifier('admin'));
        self::assertSame('andrea@example.ch', $on->adminIdentifier('andrea@example.ch'));
        self::assertSame('Admin', $on->managerIdentifier('Admin'));
    }

    public function test_the_kernel_refuses_to_boot_with_the_flag_on_the_test_database(): void
    {
        $saved = $_SERVER['APP_REVIEW_MODE'] ?? null;
        $_SERVER['APP_REVIEW_MODE'] = '1';
        try {
            (new Kernel('test', false))->boot();
            self::fail('The kernel booted in review mode outside the review stack.');
        } catch (\LogicException $refused) {
            self::assertStringStartsWith('Refusing to boot: APP_REVIEW_MODE runs only with APP_ENV=prod.', $refused->getMessage());
            self::assertStringContainsString('DATABASE_URL', $refused->getMessage());
        } finally {
            if (null === $saved) {
                unset($_SERVER['APP_REVIEW_MODE']);
            } else {
                $_SERVER['APP_REVIEW_MODE'] = $saved;
            }
        }
    }

    public function test_compose_review_yaml_passes_its_own_guard_and_publishes_only_on_localhost(): void
    {
        $compose = Yaml::parse(self::file('compose.review.yaml'), Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $environment = $compose['services']['php']['environment'];
        self::assertIsArray($environment);

        self::assertSame('1', $environment['APP_REVIEW_MODE']);
        self::assertSame([], ReviewMode::problems($environment, 'prod'));
        self::assertSame(['127.0.0.1:8098:80'], $compose['services']['edge']['ports']);
        self::assertSame(['127.0.0.1:8099:8025'], $compose['services']['mailpit']['ports']->getValue());
    }

    public function test_the_edge_marks_every_response_noindex_and_sends_tunnel_http_to_https(): void
    {
        $edge = self::file('docker/review/edge.conf');

        // At server level with `always`, and no location sets its own header (which would drop the inherited one).
        self::assertMatchesRegularExpression('/^    add_header X-Robots-Tag "noindex, nofollow" always;$/m', $edge);
        self::assertSame(1, substr_count($edge, 'add_header'));
        self::assertStringContainsString('proxy_hide_header X-Robots-Tag;', $edge);
        self::assertStringContainsString('return 301 https://$host$request_uri;', $edge);
    }

    /**
     * @return array<string, string>
     */
    private static function reviewEnv(): array
    {
        return [
            'APP_REVIEW_MODE' => '1',
            'DATABASE_URL' => 'postgresql://app:app@database:5432/review?serverVersion=18&charset=utf8',
            'MAILER_DSN' => 'smtp://mailpit:1025',
            'GOOGLE_MAPS_EMBED_KEY' => '',
            'GOOGLE_MAPS_JS_KEY' => '',
            'GOOGLE_PLACES_API_KEY' => '',
            'GOOGLE_PLACES_PHOTOS_KEY' => '',
            'GOOGLE_RATING_ENABLED' => '0',
            'SENTRY_DSN' => '',
        ];
    }

    private static function file(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$name);
    }
}
