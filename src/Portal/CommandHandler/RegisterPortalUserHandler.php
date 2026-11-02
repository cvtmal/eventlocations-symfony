<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\RegisterPortalUser;
use App\Portal\Entity\PortalUser;
use App\Portal\Exception\PortalEmailTaken;
use App\Portal\Mailer\PortalMailer;
use App\Portal\Repository\PortalUserRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns nothing, whatever it found: a known address must look like a new one from outside.
 */
final class RegisterPortalUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly PortalMailer $mailer,
    ) {
    }

    public function __invoke(RegisterPortalUser $command): void
    {
        $known = $this->users->findOneByEmail($command->email);
        if (null !== $known) {
            if ($known->isActive() && null === $known->passwordHash()) {
                $this->mailer->sendRegistrationLink($known);
            }

            return;
        }

        $user = new PortalUser($command->id, $command->email, $command->firstName, $command->lastName);
        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw PortalEmailTaken::onRegistration();
        }

        $this->mailer->sendRegistrationLink($user);
    }
}
