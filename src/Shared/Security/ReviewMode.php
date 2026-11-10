<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The operator's review demo (`make review-up`, docs/operations.md «Review demo for the operator»): the prod-mode
 * stack of compose.review.yaml on demo data, behind a Cloudflare quick tunnel. With `APP_REVIEW_MODE=1` the back office
 * asks no TOTP code and needs no enrolment, `admin` logs in to /admin and /konto (the two accounts
 * `app:review:seed-accounts` writes), and every response says noindex. The owner accepted that anyone with the tunnel
 * link can log in on fake data.
 *
 * {@see self::problems()} keeps the flag on that stack: `App\Kernel::boot()` refuses to start with it on anywhere
 * else (another database, a real mailer, a Google key, Sentry, an environment other than prod), and
 * `app:prod:check-env`, `bin/deploy`, `bin/deploy-gate` and `make release-check` refuse it outright.
 */
final readonly class ReviewMode
{
    public const string FLAG = 'APP_REVIEW_MODE';

    /** What the operator types in both login forms, as user name and as password. */
    public const string LOGIN = 'admin';
    public const string PASSWORD = 'admin';

    /** The addresses behind {@see self::LOGIN}; mails to them land in the review stack's Mailpit. */
    public const string ADMIN_EMAIL = 'review-admin@deine-eventlocation.test';
    public const string MANAGER_EMAIL = 'review-manager@deine-eventlocation.test';

    /** compose.review.yaml's database: its own Compose service and a name no other stack uses. */
    public const string DATABASE_HOST = 'database';
    public const string DATABASE_NAME = 'review';

    private const array MAILER_DSNS = ['smtp://mailpit:1025', 'null://null'];

    /** DBAL merges the query over host and path (`?host=`, `?dbname=`, `?unix_socket=` …): only these may stay. */
    private const array DATABASE_URL_QUERY_KEYS = ['serverVersion', 'charset'];

    /** Every outside service with a key or a switch: Google (D48), Sentry. */
    private const array MUST_BE_EMPTY = [
        'GOOGLE_MAPS_EMBED_KEY',
        'GOOGLE_MAPS_JS_KEY',
        'GOOGLE_PLACES_API_KEY',
        'GOOGLE_PLACES_PHOTOS_KEY',
        'SENTRY_DSN',
    ];

    public function __construct(
        #[Autowire('%env(bool:default::APP_REVIEW_MODE)%')]
        private bool $on,
    ) {
    }

    public function isOn(): bool
    {
        return $this->on;
    }

    /** The e-mail address a back-office login name stands for: {@see self::LOGIN} only in review mode. */
    public function adminIdentifier(string $typed): string
    {
        return $this->on && self::isLogin($typed) ? self::ADMIN_EMAIL : $typed;
    }

    /** The e-mail address a /konto login name stands for: {@see self::LOGIN} only in review mode. */
    public function managerIdentifier(string $typed): string
    {
        return $this->on && self::isLogin($typed) ? self::MANAGER_EMAIL : $typed;
    }

    /** A phone keyboard may capitalise it or add a space. */
    private static function isLogin(string $typed): bool
    {
        return self::LOGIN === mb_strtolower(trim($typed));
    }

    /** Symfony's `bool:` env processor, so the shell guards and the container read the same value as on. */
    public static function flagIsOn(mixed $value): bool
    {
        if (!\is_scalar($value)) {
            return false;
        }
        $value = (string) $value;

        return (bool) (filter_var($value, \FILTER_VALIDATE_BOOL) ?: filter_var($value, \FILTER_VALIDATE_INT) ?: filter_var($value, \FILTER_VALIDATE_FLOAT));
    }

    /**
     * Why the flag must not be on in this process: one line per rule, names only, never a value. Empty when the flag is
     * off, or on in the review stack as compose.review.yaml sets it up.
     *
     * @param array<string, mixed> $env
     *
     * @return list<string>
     */
    public static function problems(array $env, string $environment): array
    {
        if (!self::flagIsOn($env[self::FLAG] ?? '')) {
            return [];
        }

        $value = static function (string $name) use ($env): string {
            $value = $env[$name] ?? '';

            return \is_scalar($value) ? (string) $value : '';
        };
        $problems = [];

        if ('prod' !== $environment) {
            $problems[] = 'APP_REVIEW_MODE runs only with APP_ENV=prod.';
        }
        // Dotenv::bootEnv()'s own reading, as app:prod:check-env: a debug page through a public tunnel shows the env.
        if ((int) $value('APP_DEBUG') || filter_var($value('APP_DEBUG'), \FILTER_VALIDATE_BOOL)) {
            $problems[] = 'APP_REVIEW_MODE needs APP_DEBUG to be off.';
        }

        $database = parse_url($value('DATABASE_URL'));
        parse_str(\is_array($database) ? ($database['query'] ?? '') : '', $query);
        if (!\is_array($database)
            || self::DATABASE_HOST !== ($database['host'] ?? '')
            || '/'.self::DATABASE_NAME !== ($database['path'] ?? '')
            || [] !== array_diff(array_keys($query), self::DATABASE_URL_QUERY_KEYS)) {
            $problems[] = \sprintf('APP_REVIEW_MODE needs DATABASE_URL on the review stack\'s database (%s on host %s).', self::DATABASE_NAME, self::DATABASE_HOST);
        }

        if (!\in_array($value('MAILER_DSN'), self::MAILER_DSNS, true)) {
            $problems[] = 'APP_REVIEW_MODE needs MAILER_DSN to be Mailpit (smtp://mailpit:1025) or null://null.';
        }

        foreach (self::MUST_BE_EMPTY as $name) {
            if ('' !== $value($name)) {
                $problems[] = \sprintf('APP_REVIEW_MODE needs %s to be empty.', $name);
            }
        }
        if (self::flagIsOn($value('GOOGLE_RATING_ENABLED'))) {
            $problems[] = 'APP_REVIEW_MODE needs GOOGLE_RATING_ENABLED to be off.';
        }

        return $problems;
    }
}
