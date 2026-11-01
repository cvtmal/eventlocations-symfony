<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #20: the back office's limiters share the lock directory of the public ones, and FlockStore
 * keeps a file per limiter key. Login throttling hashes IP and e-mail itself, and `admin_totp`
 * counts by user id, so neither needs ClientIpKey; this pins that they stay that way.
 */
final class LimiterLockNamesTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const IP = '198.51.100.31';

    public function test_a_failed_login_leaves_no_address_and_no_email_in_a_lock_file_name(): void
    {
        // New per run, so the local limiter's file is new: FlockStore's files outlive a run.
        $email = 'lock-name-'.bin2hex(random_bytes(4)).'@example.ch';
        $this->client->setServerParameter('REMOTE_ADDR', self::IP);
        $before = self::lockFiles();

        $this->submitPassword($email, 'wrong-password-here');

        $new = array_diff(self::lockFiles(), $before);
        self::assertNotSame([], array_filter($new, static fn (string $file): bool => str_starts_with($file, 'sf._login_local_')), 'Nothing was locked, so this test proves nothing.');
        $this->assertNameless($email);
    }

    public function test_a_wrong_code_leaves_only_the_user_id_in_a_lock_file_name(): void
    {
        $staff = $this->createStaff();
        $email = $staff['user']->email();
        $this->client->setServerParameter('REMOTE_ADDR', self::IP);
        $this->submitPassword($email);

        $this->submitCode('000000');

        $lockName = 'sf.admin_totp-'.$staff['user']->id().'.';
        self::assertNotSame([], array_filter(self::lockFiles(), static fn (string $file): bool => str_starts_with($file, $lockName)), 'Nothing was locked, so this test proves nothing.');
        $this->assertNameless($email);
    }

    private function assertNameless(string $email): void
    {
        // FlockStore turns everything but letters, digits, dot, dash and underscore into a dash.
        $emailInAName = (string) preg_replace('/[^a-z0-9\._-]+/i', '-', $email);

        self::assertSame([], array_filter(
            self::lockFiles(),
            static fn (string $file): bool => str_contains($file, self::IP) || str_contains($file, $emailInAName),
        ));
    }

    /**
     * @return list<string>
     */
    private static function lockFiles(): array
    {
        $files = scandir(\dirname(__DIR__, 3).'/var/lock');
        self::assertIsArray($files);

        return array_values(array_diff($files, ['.', '..']));
    }
}
