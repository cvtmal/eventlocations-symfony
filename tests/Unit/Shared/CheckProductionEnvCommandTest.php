<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Console\CheckProductionEnvCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

/**
 * #20 PD8: the deploy's stop before a migration. Every line names a variable and never its value,
 * because the output lands in CI logs and cron mail. All values here are fictional.
 */
final class CheckProductionEnvCommandTest extends TestCase
{
    private const COMMITTED_KEY = 'BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwc=';
    private const DATABASE_PASSWORD = 'fake-db-password-7c1e';
    private const DATABASE_HOST = 'ep-fake-endpoint.eu-central-1.aws.neon.tech';
    private const DATABASE_URL = 'postgresql://site:'.self::DATABASE_PASSWORD.'@'.self::DATABASE_HOST.'/site?serverVersion=18&charset=utf8&sslmode=require';

    public function test_a_valid_environment_has_no_problem(): void
    {
        self::assertSame([], CheckProductionEnvCommand::problems(self::validEnv(), self::COMMITTED_KEY));
    }

    /**
     * @param array<string, string|null> $override null removes the variable
     */
    #[DataProvider('brokenEnvironments')]
    public function test_a_broken_rule_gives_one_line_with_the_name_and_without_the_value(string $variable, array $override, string $secret): void
    {
        $problems = CheckProductionEnvCommand::problems(self::env($override), self::COMMITTED_KEY);

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertStringContainsString($variable, $problems[0]);
        self::assertStringNotContainsString($secret, $problems[0]);
    }

    /**
     * @return iterable<string, array{string, array<string, string|null>, string}>
     */
    public static function brokenEnvironments(): iterable
    {
        yield 'APP_ENV dev' => ['APP_ENV', ['APP_ENV' => 'staging-marker'], 'staging-marker'];
        yield 'APP_ENV missing' => ['APP_ENV', ['APP_ENV' => null], self::DATABASE_PASSWORD];
        yield 'APP_DEBUG 1' => ['APP_DEBUG', ['APP_DEBUG' => '1'], self::DATABASE_PASSWORD];
        yield 'APP_DEBUG true' => ['APP_DEBUG', ['APP_DEBUG' => 'true'], 'true'];
        yield 'APP_SECRET short' => ['APP_SECRET', ['APP_SECRET' => 'short-secret-marker'], 'short-secret-marker'];
        yield 'APP_SECRET one too short' => ['APP_SECRET', ['APP_SECRET' => str_repeat('s', 31)], str_repeat('s', 31)];
        yield 'APP_SECRET empty' => ['APP_SECRET', ['APP_SECRET' => ''], self::DATABASE_PASSWORD];
        yield 'ADMIN_TOTP_KEY not base64' => ['ADMIN_TOTP_KEY', ['ADMIN_TOTP_KEY' => 'not base64 marker!'], 'not base64 marker!'];
        yield 'ADMIN_TOTP_KEY wrong length' => ['ADMIN_TOTP_KEY', ['ADMIN_TOTP_KEY' => base64_encode(str_repeat("\x01", 31))], base64_encode(str_repeat("\x01", 31))];
        yield 'ADMIN_TOTP_KEY the committed key' => ['ADMIN_TOTP_KEY', ['ADMIN_TOTP_KEY' => self::COMMITTED_KEY], self::COMMITTED_KEY];
        yield 'INQUIRY_RECIPIENT_OVERRIDE set' => ['INQUIRY_RECIPIENT_OVERRIDE', ['INQUIRY_RECIPIENT_OVERRIDE' => 'someone@example.ch'], 'someone@example.ch'];
        yield 'ADMIN_DEV_AUTOLOGIN set' => ['ADMIN_DEV_AUTOLOGIN', ['ADMIN_DEV_AUTOLOGIN' => 'autologin-marker'], 'autologin-marker'];
        yield 'DEFAULT_URI local' => ['DEFAULT_URI', ['DEFAULT_URI' => 'http://localhost:8080'], 'localhost'];
        yield 'DEFAULT_URI with a slash' => ['DEFAULT_URI', ['DEFAULT_URI' => 'https://deine-eventlocation.ch/'], 'deine-eventlocation.ch/'];
        yield 'DATABASE_URL other scheme' => ['DATABASE_URL', ['DATABASE_URL' => 'mysql://site:'.self::DATABASE_PASSWORD.'@'.self::DATABASE_HOST.'/site?sslmode=require'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL compose host' => ['DATABASE_URL', ['DATABASE_URL' => self::urlOn('database')], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL localhost' => ['DATABASE_URL', ['DATABASE_URL' => self::urlOn('LOCALHOST')], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL without sslmode' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://site:'.self::DATABASE_PASSWORD.'@'.self::DATABASE_HOST.'/site?serverVersion=18'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL weak sslmode' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://site:'.self::DATABASE_PASSWORD.'@'.self::DATABASE_HOST.'/site?sslmode=prefer'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL percent sign' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://site:fake%40word@'.self::DATABASE_HOST.'/site?sslmode=require'], 'fake%40word'];
        yield 'DATABASE_URL connect_timeout' => ['DATABASE_URL', ['DATABASE_URL' => self::DATABASE_URL.'&connect_timeout=10'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL channel_binding' => ['DATABASE_URL', ['DATABASE_URL' => self::DATABASE_URL.'&channel_binding=require'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL both dead options, one line' => ['DATABASE_URL', ['DATABASE_URL' => self::DATABASE_URL.'&connect_timeout=10&channel_binding=require'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL connect_timeout upper case' => ['DATABASE_URL', ['DATABASE_URL' => self::DATABASE_URL.'&CONNECT_TIMEOUT=10'], self::DATABASE_PASSWORD];
        yield 'DATABASE_URL channel_binding upper case' => ['DATABASE_URL', ['DATABASE_URL' => self::DATABASE_URL.'&Channel_Binding=require'], self::DATABASE_PASSWORD];
        yield 'MAILER_DSN null' => ['MAILER_DSN', ['MAILER_DSN' => 'null://null'], 'null://null'];
        yield 'MAILER_DSN empty' => ['MAILER_DSN', ['MAILER_DSN' => ''], self::DATABASE_PASSWORD];
        yield 'MAILER_DSN mailpit' => ['MAILER_DSN', ['MAILER_DSN' => 'smtp://mailpit:1025'], 'smtp://mailpit:1025'];
        yield 'MAILER_FROM other domain' => ['MAILER_FROM', ['MAILER_FROM' => 'Site <hallo@example.ch>'], 'hallo@example.ch'];
        yield 'MAILER_FROM bare address' => ['MAILER_FROM', ['MAILER_FROM' => 'hallo@deine-eventlocation.ch'], 'hallo@deine-eventlocation.ch'];
        yield 'MEDIA_ROOT relative' => ['MEDIA_ROOT', ['MEDIA_ROOT' => 'var/media-marker'], 'var/media-marker'];
        yield 'MEDIA_ROOT missing' => ['MEDIA_ROOT', ['MEDIA_ROOT' => null], self::DATABASE_PASSWORD];
        yield 'MEDIA_ROOT .env default' => ['MEDIA_ROOT', ['MEDIA_ROOT' => '%kernel.project_dir%/var/media'], 'kernel.project_dir'];
        yield 'MEDIA_PUBLIC_DIR relative' => ['MEDIA_PUBLIC_DIR', ['MEDIA_PUBLIC_DIR' => 'public/media-marker'], 'public/media-marker'];
        yield 'GOOGLE_MAPS_MAP_ID demo with a key' => ['GOOGLE_MAPS_MAP_ID', ['GOOGLE_MAPS_JS_KEY' => 'maps-key-marker', 'GOOGLE_MAPS_MAP_ID' => 'DEMO_MAP_ID'], 'maps-key-marker'];
        yield 'GOOGLE_MAPS_MAP_ID empty with a key' => ['GOOGLE_MAPS_MAP_ID', ['GOOGLE_MAPS_JS_KEY' => 'maps-key-marker', 'GOOGLE_MAPS_MAP_ID' => ''], 'maps-key-marker'];
        // F16 S2: placeholders of deploy/env.local.example inside values that pass their own rule.
        yield 'DATABASE_URL a Neon placeholder' => ['DATABASE_URL', ['DATABASE_URL' => 'postgresql://site:'.self::DATABASE_PASSWORD.'@__NEON_ENDPOINT__.eu-central-1.aws.neon.tech/site?sslmode=require'], '__NEON_ENDPOINT__'];
        yield 'MAILER_DSN a password placeholder' => ['MAILER_DSN', ['MAILER_DSN' => 'smtp://hallo%40deine-eventlocation.ch:__MAILER_PASSWORD__@mail.infomaniak.com:587'], '__MAILER_PASSWORD__'];
        yield 'a variable no rule knows' => ['MEDIA_BACKUP_SECRET_ACCESS_KEY', ['MEDIA_BACKUP_SECRET_ACCESS_KEY' => '__MEDIA_BACKUP_SECRET_ACCESS_KEY__'], '__MEDIA'];
    }

    public function test_the_database_url_is_named_only_once_for_both_dead_options(): void
    {
        $problems = CheckProductionEnvCommand::problems(
            self::env(['DATABASE_URL' => self::DATABASE_URL.'&channel_binding=require&connect_timeout=10']),
            self::COMMITTED_KEY,
        );

        self::assertCount(1, $problems);
        // The line must not hand an operator the option back: neither goes into the URL.
        self::assertStringNotContainsString('channel_binding=', $problems[0]);
        self::assertStringNotContainsString('connect_timeout=', $problems[0]);
        self::assertStringNotContainsString(self::DATABASE_HOST, $problems[0]);
    }

    public function test_several_broken_rules_each_give_a_line(): void
    {
        $problems = CheckProductionEnvCommand::problems(
            self::env(['APP_ENV' => 'dev', 'MAILER_DSN' => 'null://null', 'MEDIA_ROOT' => 'var/media']),
            self::COMMITTED_KEY,
        );

        self::assertCount(3, $problems);
    }

    /**
     * @param array<string, string|null> $override
     */
    #[DataProvider('passingVariants')]
    public function test_values_a_rule_must_let_through(array $override): void
    {
        self::assertSame([], CheckProductionEnvCommand::problems(self::env($override), self::COMMITTED_KEY));
    }

    /**
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function passingVariants(): iterable
    {
        yield 'APP_DEBUG 0' => [['APP_DEBUG' => '0']];
        yield 'APP_DEBUG false' => [['APP_DEBUG' => 'false']];
        yield 'APP_DEBUG absent' => [['APP_DEBUG' => null]];
        yield 'APP_SECRET exactly 32' => [['APP_SECRET' => str_repeat('s', 32)]];
        yield 'MEDIA_PUBLIC_DIR an absolute path' => [['MEDIA_PUBLIC_DIR' => '/home/site/media']];
        yield 'optional SENTRY_DSN' => [['SENTRY_DSN' => 'https://key@o1.ingest.de.sentry.io/1']];
        yield 'optional SYMFONY_TRUSTED_PROXIES' => [['SYMFONY_TRUSTED_PROXIES' => '127.0.0.1']];
        yield 'a clean url with more options' => [['DATABASE_URL' => self::DATABASE_URL.'&application_name=site']];
        yield 'double underscores around lower case' => [['MEDIA_BACKUP_REPOSITORY' => 's3:https://s3.example.ch/__bucket__/site']];
        yield 'a lone double underscore' => [['SENTRY_DSN' => 'https://key__@o1.ingest.de.sentry.io/1']];
    }

    public function test_the_non_string_values_of_server_are_skipped(): void
    {
        $env = ['argv' => ['bin/console', '__NOT_A_VALUE__'], 'argc' => 2, 'REQUEST_TIME' => 1] + self::validEnv();

        self::assertSame([], CheckProductionEnvCommand::problems($env, self::COMMITTED_KEY));
    }

    public function test_a_key_with_a_real_map_id_and_no_key_at_all_are_fine(): void
    {
        self::assertSame([], CheckProductionEnvCommand::problems(self::env(['GOOGLE_MAPS_JS_KEY' => 'k', 'GOOGLE_MAPS_MAP_ID' => 'abc123']), self::COMMITTED_KEY));
        self::assertSame([], CheckProductionEnvCommand::problems(self::env(['GOOGLE_MAPS_JS_KEY' => '', 'GOOGLE_MAPS_MAP_ID' => 'DEMO_MAP_ID']), self::COMMITTED_KEY));
    }

    public function test_the_media_public_dir_default_is_the_literal_in_env_file(): void
    {
        $dotenv = self::dotenv();

        self::assertArrayHasKey('MEDIA_PUBLIC_DIR', $dotenv);
        self::assertStringStartsWith('%kernel.project_dir%', $dotenv['MEDIA_PUBLIC_DIR'], 'A production that leaves this variable must keep passing.');
        self::assertSame([], CheckProductionEnvCommand::problems(self::env(['MEDIA_PUBLIC_DIR' => $dotenv['MEDIA_PUBLIC_DIR']]), self::COMMITTED_KEY));
    }

    public function test_the_key_committed_in_env_file_is_refused(): void
    {
        $committed = self::dotenv()['ADMIN_TOTP_KEY'];

        self::assertSame(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES, \strlen((string) base64_decode($committed, true)));

        $problems = CheckProductionEnvCommand::problems(self::env(['ADMIN_TOTP_KEY' => $committed]), $committed);

        self::assertCount(1, $problems);
        self::assertStringContainsString('ADMIN_TOTP_KEY', $problems[0]);
        self::assertStringNotContainsString($committed, $problems[0]);
    }

    public function test_the_template_passes_over_env_file_once_its_placeholders_are_filled(): void
    {
        $dotenv = self::dotenv();

        self::assertSame([], CheckProductionEnvCommand::problems(self::filledExample() + $dotenv, $dotenv['ADMIN_TOTP_KEY']));
    }

    public function test_the_template_alone_lacks_only_media_public_dir(): void
    {
        $problems = CheckProductionEnvCommand::problems(self::filledExample(), self::dotenv()['ADMIN_TOTP_KEY']);

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertStringContainsString('MEDIA_PUBLIC_DIR', $problems[0]);
    }

    public function test_the_template_keeps_a_placeholder_in_every_secret(): void
    {
        $example = (new Dotenv())->parse(self::projectFile('deploy/env.local.example'));
        $problems = CheckProductionEnvCommand::problems($example + self::dotenv(), self::dotenv()['ADMIN_TOTP_KEY']);

        self::assertNotSame([], $problems, 'An unfilled template must never pass the guard.');
        self::assertStringContainsString('__APP_SECRET__', $example['APP_SECRET']);
        self::assertStringContainsString('__ADMIN_TOTP_KEY__', $example['ADMIN_TOTP_KEY']);
        self::assertStringContainsString('__NEON_PASSWORD__', $example['DATABASE_URL']);
        self::assertStringContainsString('__MAILER_PASSWORD__', $example['MAILER_DSN']);
    }

    public function test_the_unfilled_template_names_every_variable_with_a_placeholder(): void
    {
        $example = (new Dotenv())->parse(self::projectFile('deploy/env.local.example'));
        $problems = implode("\n", CheckProductionEnvCommand::problems($example + self::dotenv(), self::dotenv()['ADMIN_TOTP_KEY']));

        $withPlaceholder = array_keys(array_filter($example, static fn (string $value): bool => str_contains($value, '__')));
        self::assertContains('MEDIA_BACKUP_REPOSITORY', $withPlaceholder);
        foreach ($withPlaceholder as $name) {
            self::assertStringContainsString($name.' still holds a __PLACEHOLDER__', $problems);
        }
        self::assertDoesNotMatchRegularExpression('/__(?!PLACEHOLDER__)[A-Z_]+__/', $problems, 'A line never quotes the placeholder itself.');
    }

    public function test_the_template_sends_channel_binding_through_the_process_variable(): void
    {
        $template = self::projectFile('deploy/env.local.example');

        self::assertSame('require', (new Dotenv())->parse($template)['PGCHANNELBINDING'] ?? null);

        // Commented lines too: an owner may uncomment the second shape.
        $urls = preg_grep('/DATABASE_URL\s*=/', explode("\n", $template));
        self::assertNotFalse($urls);
        self::assertCount(2, $urls);
        foreach ($urls as $url) {
            self::assertStringNotContainsStringIgnoringCase('connect_timeout', $url);
            self::assertStringNotContainsStringIgnoringCase('channel_binding', $url);
            self::assertStringContainsString('sslmode=require', $url);
        }
    }

    public function test_the_command_fails_and_prints_the_lines(): void
    {
        [$status, $output] = self::runCommand(['APP_ENV' => 'staging-marker'] + self::validEnv());

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('APP_ENV must be prod.', $output);
        self::assertStringNotContainsString('staging-marker', $output);
    }

    public function test_the_command_refuses_the_key_committed_in_env_file(): void
    {
        // Through __invoke and the real .env: problems() alone is handed the key by its caller, so
        // a broken lookup would give '' here and let the committed key pass.
        $committed = self::dotenv()['ADMIN_TOTP_KEY'];

        [$status, $output] = self::runCommand(['ADMIN_TOTP_KEY' => $committed] + self::validEnv());

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('ADMIN_TOTP_KEY must not be the key committed in .env.', $output);
        self::assertStringNotContainsString($committed, $output);
    }

    public function test_the_command_prints_ok_for_a_valid_environment(): void
    {
        [$status, $output] = self::runCommand(self::validEnv());

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('OK', trim($output));
    }

    public function test_an_operator_placeholder_gives_one_line_with_the_parameter_and_without_the_value(): void
    {
        $facts = ['site.operator_name' => 'Muster AG', 'site.operator_uid' => 'TODO(owner): UID-Nummer'];

        self::assertSame(
            ['site.operator_uid in config/services.yaml must be the operator\'s fact, not a TODO(owner) placeholder (D27).'],
            CheckProductionEnvCommand::placeholderProblems($facts),
        );
        self::assertSame([], CheckProductionEnvCommand::placeholderProblems(['site.operator_name' => 'Muster AG']));
    }

    public function test_the_command_refuses_a_placeholder_in_a_valid_environment(): void
    {
        [$status, $output] = self::runCommand(self::validEnv(), ['site.operator_street' => 'TODO(owner): Strasse und Nummer']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('site.operator_street in config/services.yaml must be', $output);
        self::assertStringNotContainsString('Strasse und Nummer', $output);
    }

    public function test_the_command_checks_every_operator_fact_of_the_services_file(): void
    {
        // A new site.operator_* fact without a line in the constructor's #[Autowire] map would escape D27's guard.
        $parameters = Yaml::parseFile(\dirname(__DIR__, 3).'/config/services.yaml')['parameters'];
        $facts = array_keys(array_filter($parameters, static fn (string $name): bool => str_starts_with($name, 'site.operator_'), \ARRAY_FILTER_USE_KEY));
        $autowire = (new \ReflectionMethod(CheckProductionEnvCommand::class, '__construct'))->getParameters()[1]->getAttributes(Autowire::class)[0]->newInstance();
        self::assertIsArray($autowire->value);

        self::assertSame($facts, array_keys($autowire->value));
        foreach ($autowire->value as $name => $placeholder) {
            self::assertSame('%'.$name.'%', $placeholder);
        }
    }

    /**
     * @param array<string, string> $env
     * @param array<string, string> $operatorFacts
     *
     * @return array{int, string}
     */
    private static function runCommand(array $env, array $operatorFacts = ['site.operator_name' => 'Muster AG']): array
    {
        $saved = $_SERVER;
        $_SERVER = $env + $_SERVER;
        $output = new BufferedOutput();

        try {
            $status = (new CheckProductionEnvCommand(\dirname(__DIR__, 3), $operatorFacts))(new SymfonyStyle(new ArrayInput([]), $output));
        } finally {
            $_SERVER = $saved;
        }

        return [$status, $output->fetch()];
    }

    /**
     * @param array<string, string|null> $override
     *
     * @return array<string, string>
     */
    private static function env(array $override): array
    {
        return array_filter($override + self::validEnv(), static fn (?string $value): bool => null !== $value);
    }

    /**
     * @return array<string, string>
     */
    private static function validEnv(): array
    {
        return [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => str_repeat('a1', 32),
            'ADMIN_TOTP_KEY' => base64_encode(str_repeat("\x05", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            'INQUIRY_RECIPIENT_OVERRIDE' => '',
            'ADMIN_DEV_AUTOLOGIN' => '',
            'DEFAULT_URI' => 'https://deine-eventlocation.ch',
            'DATABASE_URL' => self::DATABASE_URL,
            'MAILER_DSN' => 'smtp://fake:fake@mail.example.ch:587',
            'MAILER_FROM' => 'deine-eventlocation.ch <hallo@deine-eventlocation.ch>',
            'MEDIA_ROOT' => '/var/www/deine-eventlocation.ch/shared/var/media',
            'MEDIA_PUBLIC_DIR' => '%kernel.project_dir%/public/media',
        ];
    }

    private static function urlOn(string $host): string
    {
        return 'postgresql://site:'.self::DATABASE_PASSWORD.'@'.$host.':5432/site?sslmode=require';
    }

    /**
     * deploy/env.local.example with fakes in its placeholders.
     *
     * @return array<string, string>
     */
    private static function filledExample(): array
    {
        $filled = strtr(self::projectFile('deploy/env.local.example'), [
            '__APP_SECRET__' => str_repeat('c3', 32),
            '__ADMIN_TOTP_KEY__' => base64_encode(str_repeat("\x09", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            '__NEON_USER__' => 'site',
            '__NEON_PASSWORD__' => self::DATABASE_PASSWORD,
            '__NEON_ENDPOINT__' => 'ep-fake-endpoint',
            '__NEON_DATABASE__' => 'site',
            '__MAILER_PASSWORD__' => 'fake-mail-password',
            '__MEDIA_BACKUP_BUCKET__' => 'fake-bucket',
            '__MEDIA_BACKUP_PASSWORD__' => 'fake-backup-password',
            '__MEDIA_BACKUP_ACCESS_KEY_ID__' => 'fake-backup-key',
            '__MEDIA_BACKUP_SECRET_ACCESS_KEY__' => 'fake-backup-secret',
        ]);

        $values = (new Dotenv())->parse($filled);
        self::assertStringNotContainsString('__', implode("\n", $values), 'A placeholder without a fake here would hide a broken rule.');

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function dotenv(): array
    {
        return (new Dotenv())->parse(self::projectFile('.env'));
    }

    private static function projectFile(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$name);
    }
}
