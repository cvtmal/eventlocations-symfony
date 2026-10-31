<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;

/**
 * The query pins of the CRM pages (P12, `MoreFilterTest`'s pattern): the statements one GET runs on a
 * client that does not reboot. The logged-in user's refresh is not among them: the test's user is in the
 * entity manager's identity map. In production it is one statement more, which the P12 ceilings include.
 */
trait CountsQueries
{
    private function queriesOf(string $url): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return \count($holder->getData()['default'] ?? []);
    }
}
