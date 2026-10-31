<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\Extension\TimelineActivityType;
use App\Admin\Extension\TimelineActivityTypeRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * A type is three things that must agree: a row in `crm_activity_types`, a registered TimelineActivityType and a
 * line in `admin-ui/src/timeline/types.js`. A later cycle that adds one of them alone fails here. Every registered
 * type also needs `activity.tab.<code>`, composable or not, or its filter tab shows the raw key.
 */
final class TimelineTypesPinTest extends KernelTestCase
{
    public function test_every_activity_type_row_has_a_registered_type_and_a_browser_line(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('pin', null, ['ROLE_ADMIN']), 'admin', ['ROLE_ADMIN']));
        $registry = self::getContainer()->get(TimelineActivityTypeRegistry::class);
        self::assertInstanceOf(TimelineActivityTypeRegistry::class, $registry);

        /** @var list<string> $rows */
        $rows = $connection->fetchFirstColumn('SELECT code FROM crm_activity_types ORDER BY position');
        $registered = array_map(static fn (TimelineActivityType $type): string => $type->code(), $registry->visible());
        $script = (string) file_get_contents(\dirname(__DIR__, 3).'/admin-ui/src/timeline/types.js');
        preg_match_all('/^    ([a-z_]+): \{ icon: /m', $script, $matches);

        self::assertNotSame([], $rows);
        $sorted = static function (array $values): array {
            sort($values);

            return $values;
        };
        self::assertSame($sorted($rows), $sorted($registered), 'crm_activity_types and the registered TimelineActivityTypes list the same codes.');
        self::assertSame($sorted($rows), $sorted($matches[1]), 'crm_activity_types and admin-ui/src/timeline/types.js list the same codes.');

        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');
        foreach ($registered as $code) {
            self::assertTrue($catalogue->defines('activity.tab.'.$code, 'crm+intl-icu'), 'activity.tab.'.$code);
        }
    }
}
