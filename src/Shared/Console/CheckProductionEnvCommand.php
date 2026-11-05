<?php

declare(strict_types=1);

namespace App\Shared\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The deploy's stop before a migration (#20, PD8): an environment that would boot but be unsafe
 * ends here. A line names a variable, never its value, because this output lands in CI logs and
 * cron mail.
 */
#[AsCommand('app:prod:check-env', 'Refuse a production environment that is unsafe (#20)')]
final class CheckProductionEnvCommand
{
    private const string SITE_URL = 'https://hochzeitslocation.ch';
    private const string SENDER_SUFFIX = '@hochzeitslocation.ch>';
    /** `.env`'s literal: production leaves it (C2c), and the env is read before Symfony resolves it. */
    private const string MEDIA_PUBLIC_DIR_DEFAULT = '%kernel.project_dir%/public/media';
    private const array COMPOSE_DATABASE_HOSTS = ['database', 'localhost'];
    private const array DATABASE_URL_DEAD_OPTIONS = ['connect_timeout', 'channel_binding'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $dotenv = file_get_contents($this->projectDir.'/.env');
        if (false === $dotenv) {
            throw new \RuntimeException('.env is unreadable, so the committed ADMIN_TOTP_KEY cannot be compared.');
        }

        // What Dotenv::bootEnv() filled, so the check sees the values the app will run with.
        $problems = self::problems($_SERVER + $_ENV, (new Dotenv())->parse($dotenv, '.env')['ADMIN_TOTP_KEY'] ?? '');

        if ([] === $problems) {
            $io->writeln('OK');

            return Command::SUCCESS;
        }

        $io->writeln($problems);

        return Command::FAILURE;
    }

    /**
     * Pure, so a unit test covers each rule.
     *
     * @param array<array-key, mixed> $env raw values: a `%kernel.project_dir%` in `.env` is still literal here
     *
     * @return list<string> one line per failed rule, each naming the variable and never its value
     */
    public static function problems(array $env, string $committedTotpKey): array
    {
        $value = static function (string $name) use ($env): string {
            $value = $env[$name] ?? '';

            return \is_scalar($value) ? (string) $value : '';
        };
        $problems = [];

        if ('prod' !== $value('APP_ENV')) {
            $problems[] = 'APP_ENV must be prod.';
        }
        // Dotenv::bootEnv()'s own reading of the flag.
        if ((int) $value('APP_DEBUG') || filter_var($value('APP_DEBUG'), \FILTER_VALIDATE_BOOL)) {
            $problems[] = 'APP_DEBUG must be off.';
        }
        if (\strlen($value('APP_SECRET')) < 32) {
            $problems[] = 'APP_SECRET must have at least 32 characters.';
        }

        $totpKey = base64_decode($value('ADMIN_TOTP_KEY'), true);
        if (false === $totpKey || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($totpKey)) {
            $problems[] = \sprintf('ADMIN_TOTP_KEY must be the base64 of %d bytes.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        } elseif ($committedTotpKey === $value('ADMIN_TOTP_KEY')) {
            $problems[] = 'ADMIN_TOTP_KEY must not be the key committed in .env.';
        }

        if ('' !== $value('INQUIRY_RECIPIENT_OVERRIDE')) {
            $problems[] = 'INQUIRY_RECIPIENT_OVERRIDE must be empty: otherwise no venue gets its inquiries and no CRM mail reaches its recipient.';
        }
        if ('' !== $value('ADMIN_DEV_AUTOLOGIN')) {
            $problems[] = 'ADMIN_DEV_AUTOLOGIN must be empty.';
        }
        if (self::SITE_URL !== $value('DEFAULT_URI')) {
            $problems[] = \sprintf('DEFAULT_URI must be %s.', self::SITE_URL);
        }

        array_push($problems, ...self::databaseUrlProblems($value('DATABASE_URL')));

        $mailerDsn = $value('MAILER_DSN');
        if ('' === $mailerDsn || str_starts_with($mailerDsn, 'null://')) {
            $problems[] = 'MAILER_DSN must name a real transport, not null://.';
        }
        if (str_contains($mailerDsn, 'mailpit')) {
            $problems[] = 'MAILER_DSN must not point at Mailpit.';
        }
        if (!str_ends_with($value('MAILER_FROM'), self::SENDER_SUFFIX)) {
            $problems[] = \sprintf('MAILER_FROM must end in %s', self::SENDER_SUFFIX);
        }

        if (!str_starts_with($value('MEDIA_ROOT'), '/')) {
            $problems[] = 'MEDIA_ROOT must be an absolute path.';
        }
        $mediaPublicDir = $value('MEDIA_PUBLIC_DIR');
        if (self::MEDIA_PUBLIC_DIR_DEFAULT !== $mediaPublicDir && !str_starts_with($mediaPublicDir, '/')) {
            $problems[] = 'MEDIA_PUBLIC_DIR must be an absolute path or .env\'s default.';
        }

        $mapId = $value('GOOGLE_MAPS_MAP_ID');
        if ('' !== $value('GOOGLE_MAPS_JS_KEY') && ('' === $mapId || 'DEMO_MAP_ID' === $mapId)) {
            $problems[] = 'GOOGLE_MAPS_MAP_ID must be the production Map ID when GOOGLE_MAPS_JS_KEY is set, not empty and not DEMO_MAP_ID.';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function databaseUrlProblems(string $url): array
    {
        $problems = [];
        $host = parse_url($url, \PHP_URL_HOST);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        if (!str_starts_with($url, 'postgresql://')) {
            $problems[] = 'DATABASE_URL must start with postgresql://.';
        }
        if (!\is_string($host) || \in_array(strtolower($host), self::COMPOSE_DATABASE_HOSTS, true)) {
            $problems[] = 'DATABASE_URL must name the production database host.';
        }
        if ('require' !== ($query['sslmode'] ?? null)) {
            $problems[] = 'DATABASE_URL must set sslmode=require.';
        }
        // DBAL builds the DSN from a fixed list of keys, so these look like a bound and give none.
        foreach (self::DATABASE_URL_DEAD_OPTIONS as $option) {
            if (false !== stripos($url, $option)) {
                $problems[] = 'DATABASE_URL must not name options that DBAL never passes on (doctrine.yaml and PGCHANNELBINDING hold them).';
                break;
            }
        }
        // doctrine.yaml reads it through resolve:, which takes % for a parameter.
        if (str_contains($url, '%')) {
            $problems[] = 'DATABASE_URL must not contain a percent sign.';
        }

        return $problems;
    }
}
