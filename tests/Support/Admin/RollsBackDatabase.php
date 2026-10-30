<?php

declare(strict_types=1);

namespace App\Tests\Support\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs a KernelTestCase in a transaction that tearDown rolls back, as the other integration tests do.
 */
trait RollsBackDatabase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }
}
