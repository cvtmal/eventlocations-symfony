<?php

declare(strict_types=1);

namespace App\Tests\Support\Cms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A second database session, which is what an advisory lock tells apart: the test's own connection
 * may take a lock it already holds. Call releaseAdvisoryLocks() in tearDown(), before the rollback.
 */
trait HoldsAdvisoryLocks
{
    private ?Connection $lockSession = null;

    /** Holds the lock until releaseAdvisoryLocks(). */
    private function holdAdvisoryLock(Connection $connection, string $name): void
    {
        $session = $this->lockSession($connection);
        $session->beginTransaction();
        $session->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$name]);
    }

    /** Whether another session has the lock. Outside a transaction a try-lock ends with its statement. */
    private function advisoryLockIsHeld(Connection $connection, string $name): bool
    {
        return !$this->lockSession($connection)->fetchOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))', [$name]);
    }

    private function releaseAdvisoryLocks(): void
    {
        if ($this->lockSession?->isTransactionActive()) {
            $this->lockSession->rollBack();
        }
        $this->lockSession?->close();
        $this->lockSession = null;
    }

    /**
     * The test's own transaction keeps an advisory lock until its rollback, so a test that already
     * refreshed or saved would block here for good; the timeout turns that into an error.
     */
    private function lockSession(Connection $connection): Connection
    {
        if (null === $this->lockSession) {
            $this->lockSession = DriverManager::getConnection($connection->getParams());
            $this->lockSession->executeStatement("SET lock_timeout = '2s'");
        }

        return $this->lockSession;
    }
}
