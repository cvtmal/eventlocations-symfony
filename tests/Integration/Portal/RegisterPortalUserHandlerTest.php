<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Portal\Command\RegisterPortalUser;
use App\Portal\CommandHandler\RegisterPortalUserHandler;
use App\Portal\Exception\PortalEmailTaken;
use App\Portal\Mailer\PortalMailer;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The third validation layer (validation.md): two requests pass the lookup, the unique index stops the second.
 * The stub stands in for the first of them, so the row is already there when this handler flushes.
 */
final class RegisterPortalUserHandlerTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_lost_race_is_a_domain_exception(): void
    {
        PortalUserFactory::createOne(['email' => 'race@example.ch']);

        $users = $this->createStub(PortalUserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $mailer = self::getContainer()->get(PortalMailer::class);
        self::assertInstanceOf(PortalMailer::class, $mailer);

        $handler = new RegisterPortalUserHandler($users, $em, $mailer);

        $this->expectException(PortalEmailTaken::class);

        $handler(new RegisterPortalUser(EntityId::generate(), 'Race@example.ch', 'Ana', 'Rennt'));
    }
}
