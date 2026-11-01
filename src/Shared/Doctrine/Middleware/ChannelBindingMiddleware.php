<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Carries PGCHANNELBINDING from .env.local to libpq (#20, C12). DBAL drops channel_binding from the
 * URL and libpq reads only the C environment, which Dotenv leaves alone. Symfony's use_putenv would
 * put every secret there, so a child process would inherit them; this sets the one variable.
 */
final readonly class ChannelBindingMiddleware implements Middleware
{
    public function __construct(
        #[Autowire('%env(default::PGCHANNELBINDING)%')]
        private ?string $channelBinding,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        if (null === $this->channelBinding || '' === $this->channelBinding) {
            return $driver;
        }

        return new ChannelBindingDriver($driver, $this->channelBinding);
    }
}
