<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * #20, F15: the files only the production VPS reads. Dev runs its own nginx file and no cron, so a typo
 * here would first show on the live site. tests/Deploy/nginx-check.sh runs the server block in a real
 * nginx; this pins what must stay true whatever is proved there.
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
        self::assertStringNotContainsString('immutable', self::location('/media/'));
        self::assertStringContainsString('max-age=86400', self::location('/media/'));
    }

    public function test_hashed_assets_are_immutable(): void
    {
        foreach (['/assets/', '/admin-build/'] as $path) {
            self::assertStringContainsString('max-age=31536000, immutable', self::location($path), $path);
        }
    }

    public function test_assets_and_media_carry_nosniff(): void
    {
        foreach (['/assets/', '/admin-build/', '/media/'] as $path) {
            self::assertStringContainsString('X-Content-Type-Options "nosniff"', self::location($path), $path);
        }
    }

    public function test_dotfiles_are_not_served(): void
    {
        self::assertMatchesRegularExpression('/location ~ \/\\\. \{\s*return 404;\s*\}/', self::nginx());
    }

    public function test_every_name_lands_on_the_canonical_host(): void
    {
        // E7: deineeventlocation.ch redirects to deine-eventlocation.ch, and so do both www names.
        $nginx = self::nginx();
        self::assertStringContainsString('server_name deine-eventlocation.ch www.deine-eventlocation.ch deineeventlocation.ch www.deineeventlocation.ch;', $nginx);
        self::assertStringContainsString('server_name www.deine-eventlocation.ch deineeventlocation.ch www.deineeventlocation.ch;', $nginx);
        self::assertSame(2, substr_count($nginx, 'return 301 https://deine-eventlocation.ch$request_uri;'));
    }

    public function test_php_runs_the_resolved_release(): void
    {
        // current is switched by bin/deploy; OPcache must key on the release path, not on the link.
        self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;', self::nginx());
        self::assertStringContainsString('root /var/www/deine-eventlocation.ch/current/public;', self::nginx());
    }

    public function test_the_php_version_header_is_removed(): void
    {
        // expose_php is PHP_INI_SYSTEM, so .user.ini cannot switch it off.
        self::assertStringContainsString('fastcgi_hide_header X-Powered-By;', self::nginx());
    }

    public function test_nginx_takes_the_bodies_php_takes(): void
    {
        self::assertSame((int) self::userIni()['post_max_size'], (int) self::firstMatch('/client_max_body_size (\d+)m;/', self::nginx()));
    }

    public function test_nginx_talks_to_the_pool_of_php_8_5(): void
    {
        $pool = self::file('deploy/php-fpm/deine-eventlocation.conf');
        $socket = self::firstMatch('/^listen = (\S+)$/m', $pool);
        self::assertStringContainsString('fastcgi_pass unix:'.$socket.';', self::nginx());
        self::assertStringContainsString('/etc/php/8.5/fpm/pool.d/', $pool);
    }

    public function test_the_crontab_has_exactly_the_four_jobs(): void
    {
        self::assertSame([
            '5 0 * * * /bin/sh /var/www/deine-eventlocation.ch/current/bin/cron-midnight',
            '2-59/5 * * * * /bin/sh /var/www/deine-eventlocation.ch/current/bin/cron-cache-guard',
            '15 3 * * * /bin/sh /var/www/deine-eventlocation.ch/current/bin/cron-daily',
            '30 4 * * * /bin/sh /var/www/deine-eventlocation.ch/current/bin/cron-media-backup',
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

        foreach (['app:inquiries:purge', 'app:crm:refresh-stages', 'app:crm:renewal-tasks', 'app:inquiries:report-stuck', 'app:stats:ingest', 'cache:pool:prune'] as $command) {
            self::assertStringContainsString($command, $cron);
        }
    }

    /**
     * F15b: tests/Deploy/media-backup-check.sh writes its own .env.local, so only this ties the script's
     * names to the template the owner fills.
     */
    public function test_the_media_backup_reads_the_names_the_template_sets(): void
    {
        preg_match_all('/env_value (MEDIA_BACKUP_[A-Z_]+)/', self::file('bin/cron-media-backup'), $read);
        preg_match_all('/^(MEDIA_BACKUP_[A-Z_]+)=/m', self::file('deploy/env.local.example'), $set);

        self::assertSame(['MEDIA_BACKUP_ACCESS_KEY_ID', 'MEDIA_BACKUP_PASSWORD', 'MEDIA_BACKUP_REGION', 'MEDIA_BACKUP_REPOSITORY', 'MEDIA_BACKUP_SECRET_ACCESS_KEY'], self::sorted($read[1]));
        self::assertSame(self::sorted($read[1]), self::sorted($set[1]));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
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
     * The body of the nginx `location` for one path prefix.
     */
    private static function location(string $path): string
    {
        $pattern = '/^    location '.preg_quote($path, '/').' \{(.*?)^    \}/sm';
        self::assertSame(1, preg_match($pattern, self::nginx(), $matches), $path);

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

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#') && !str_starts_with($line, 'MAILTO=')));
    }

    private static function firstMatch(string $pattern, string $subject): string
    {
        if (1 !== preg_match($pattern, $subject, $matches)) {
            self::fail('No match for '.$pattern);
        }

        return $matches[1];
    }

    private static function nginx(): string
    {
        return self::file('deploy/nginx/deine-eventlocation.ch.conf');
    }

    private static function file(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$name);
    }
}
