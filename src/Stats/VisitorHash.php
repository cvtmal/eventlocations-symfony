<?php

declare(strict_types=1);

namespace App\Stats;

use App\Stats\ValueObject\SpoolEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * The cookieless visitor of a day (#47, contract C2): an HMAC of client IP and User-Agent under a
 * random salt per Zurich day, cut to 32 hex digits. The salt lives in `<salt>/<Y-m-d>` and the prune
 * deletes it once the day is over, after which nobody can link a hash to an IP again. The IP itself
 * is never written. `getClientIp()` is the visitor's behind HttpCache (`module-shared.md`).
 */
final readonly class VisitorHash
{
    private const int SALT_BYTES = 32;
    /** A second writer may have created the salt file and not yet written it: wait this often, 5 ms each. */
    private const int READ_TRIES = 20;

    public function __construct(
        private StatsPaths $paths,
    ) {
    }

    public function of(Request $request, \DateTimeImmutable $day): string
    {
        $ip = $request->getClientIp() ?? '';
        $userAgent = $request->headers->get('User-Agent') ?? '';

        return substr(hash_hmac('sha256', $ip."\n".$userAgent, $this->salt($day)), 0, 32);
    }

    private function salt(\DateTimeImmutable $day): string
    {
        $directory = $this->paths->saltDir();
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the salt directory %s.', $directory));
        }
        $path = $directory.'/'.$day->setTimezone(new \DateTimeZone(SpoolEvent::ZONE))->format('Y-m-d');

        $handle = @fopen($path, 'x');
        if (false !== $handle) {
            $salt = random_bytes(self::SALT_BYTES);
            $written = fwrite($handle, $salt);
            fclose($handle);
            if (self::SALT_BYTES !== $written) {
                throw new \RuntimeException(\sprintf('Cannot write the salt file %s.', $path));
            }

            return $salt;
        }

        for ($try = 1; $try <= self::READ_TRIES; ++$try) {
            $salt = @file_get_contents($path);
            if (\is_string($salt) && self::SALT_BYTES === \strlen($salt)) {
                return $salt;
            }
            usleep(5000);
        }

        // A writer died between creating and filling the file: replace it, at the price of two
        // salts (so some visitors counted twice) for this day.
        $salt = random_bytes(self::SALT_BYTES);
        if (false === file_put_contents($path, $salt, \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Cannot write the salt file %s.', $path));
        }

        return $salt;
    }
}
