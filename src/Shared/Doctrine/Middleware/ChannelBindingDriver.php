<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class ChannelBindingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly string $channelBinding,
    ) {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        // On every connect, not once: php-fpm restores the environment at the end of each request.
        putenv('PGCHANNELBINDING='.$this->channelBinding);

        return parent::connect($params);
    }
}
