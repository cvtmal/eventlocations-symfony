<?php

declare(strict_types=1);

namespace App\Tests\Support\Inquiries;

use Symfony\Component\Process\Process;

/**
 * A real SMTP server for a test (`esmtp-sink.php`), for what Mailpit hides: it has no SMTPUTF8,
 * and it can refuse a recipient. Start it, put `$dsn` into `MAILER_DSN` before the kernel boots,
 * and stop it after the kernel has shut down.
 */
final class SmtpSink
{
    private function __construct(
        private readonly Process $process,
        public readonly string $dsn,
    ) {
    }

    public static function start(string $refusedRecipient = ''): self
    {
        $process = new Process([\PHP_BINARY, __DIR__.'/esmtp-sink.php', $refusedRecipient]);
        $process->setTimeout(null);
        $process->start();
        $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));

        if (1 !== preg_match('/^PORT (\d+)$/m', $process->getOutput(), $port)) {
            throw new \RuntimeException('The SMTP sink did not start: '.$process->getErrorOutput());
        }

        return new self($process, 'smtp://127.0.0.1:'.$port[1]);
    }

    /**
     * @return list<list<string>> the recipients of each message the server acknowledged
     */
    public function delivered(): array
    {
        preg_match_all('/^DELIVERED (.*)$/m', $this->process->getOutput(), $messages);

        return array_map(static fn (string $recipients): array => explode(',', $recipients), $messages[1]);
    }

    public function stop(): void
    {
        $this->process->stop(0);
    }
}
