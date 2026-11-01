<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * #20: the files only Hostpoint reads. Dev runs nginx and no cron, so a typo here would first
 * show on the live site. tests/Deploy/htaccess-check.sh runs the .htaccess in a real Apache; this
 * pins what must stay true whatever is proved there.
 */
final class HostingFilesTest extends TestCase
{
    public function test_user_ini_holds_the_production_values(): void
    {
        self::assertSame([
            'memory_limit' => '256M',
            'upload_max_filesize' => '25M',
            'post_max_size' => '26M',
            'max_execution_time' => '120',
            'default_socket_timeout' => '10',
            'display_errors' => 'Off',
            'log_errors' => 'On',
            'expose_php' => 'Off',
            'zend.exception_ignore_args' => 'On',
        ], self::userIni());
    }

    public function test_a_mail_server_cannot_hold_a_worker_for_long(): void
    {
        self::assertLessThanOrEqual(10, (int) self::userIni()['default_socket_timeout']);
    }

    public function test_a_post_at_the_upload_limit_fits_into_the_post_limit(): void
    {
        $ini = self::userIni();

        self::assertGreaterThan((int) $ini['upload_max_filesize'], (int) $ini['post_max_size']);
    }

    public function test_the_local_stack_and_production_accept_the_same_uploads(): void
    {
        // #25 tests uploads against docker/php/php.ini; production reads public/.user.ini.
        $ini = self::userIni();
        $local = parse_ini_string(self::file('docker/php/php.ini'), false, \INI_SCANNER_RAW);
        self::assertIsArray($local);

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            self::assertSame($local[$key], $ini[$key], $key);
        }
    }

    public function test_media_is_never_immutable(): void
    {
        // app:media:regenerate rewrites these files under the same URLs.
        self::assertStringNotContainsString('immutable', self::headerBlock('/media/'));
        self::assertStringContainsString('max-age=86400', self::headerBlock('/media/'));
    }

    public function test_hashed_assets_are_immutable(): void
    {
        foreach (['/assets/', '/admin-build/'] as $path) {
            self::assertStringContainsString('max-age=31536000, immutable', self::headerBlock($path), $path);
        }
    }

    public function test_assets_and_media_carry_nosniff(): void
    {
        foreach (['/assets/', '/admin-build/', '/media/'] as $path) {
            self::assertStringContainsString('X-Content-Type-Options "nosniff"', self::headerBlock($path), $path);
        }
    }

    public function test_user_ini_is_denied(): void
    {
        self::assertMatchesRegularExpression(
            '/<FilesMatch "\^\\\\\.\(user\\\\\.ini\|ht\)">\s*Require all denied\s*<\/FilesMatch>/',
            self::htaccess(),
        );
    }

    public function test_the_host_rule_comes_before_the_scheme_rule(): void
    {
        $htaccess = self::htaccess();
        $host = strpos($htaccess, 'RewriteCond %{HTTP_HOST} ^www\.hochzeitslocation\.ch$');
        $scheme = strpos($htaccess, 'RewriteCond %{HTTPS} !=on');

        self::assertNotFalse($host);
        self::assertNotFalse($scheme);
        self::assertLessThan($scheme, $host, 'http://www. must take one hop, not two.');
    }

    public function test_the_scheme_rule_respects_a_proxy_that_ended_tls(): void
    {
        self::assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !=https', self::htaccess());
    }

    public function test_the_php_version_header_is_removed(): void
    {
        // "always": without it the rule misses headers from php-fpm, and expose_php cannot help.
        self::assertStringContainsString('Header always unset X-Powered-By', self::htaccess());
    }

    public function test_the_crontab_has_exactly_the_three_jobs(): void
    {
        self::assertSame([
            '5 0 * * * /bin/sh /home/djweddin/www/hochzeitslocation.ch/bin/cron-midnight',
            '2-59/5 * * * * /bin/sh /home/djweddin/www/hochzeitslocation.ch/bin/cron-cache-guard',
            '15 3 * * * /bin/sh /home/djweddin/www/hochzeitslocation.ch/bin/cron-daily',
        ], self::cronJobs());
    }

    public function test_the_crontab_never_warms_the_cache(): void
    {
        foreach (self::cronJobs() as $job) {
            self::assertStringNotContainsString('warm-cache', $job);
        }
    }

    public function test_the_nightly_chain_names_its_commands(): void
    {
        $cron = self::file('bin/cron-daily');

        foreach (['app:inquiries:purge', 'app:crm:refresh-stages', 'app:crm:renewal-tasks', 'app:inquiries:report-stuck', 'cache:pool:prune'] as $command) {
            self::assertStringContainsString($command, $cron);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function userIni(): array
    {
        $values = parse_ini_string(self::file('public/.user.ini'), false, \INI_SCANNER_RAW);
        self::assertIsArray($values);

        return array_map(strval(...), $values);
    }

    /**
     * The body of the `<If>` for one path prefix.
     */
    private static function headerBlock(string $path): string
    {
        $pattern = '/<If "%\{REQUEST_URI\} =~ m#\^'.preg_quote($path, '/').'#">(.*?)<\/If>/s';
        self::assertSame(1, preg_match($pattern, self::htaccess(), $matches), $path);

        return $matches[1];
    }

    /**
     * Active lines only: comments may mention what the file refuses to do.
     *
     * @return list<string>
     */
    private static function cronJobs(): array
    {
        $lines = array_map(trim(...), explode("\n", self::file('deploy/crontab.txt')));

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#')));
    }

    private static function htaccess(): string
    {
        return self::file('public/.htaccess');
    }

    private static function file(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$name);
    }
}
