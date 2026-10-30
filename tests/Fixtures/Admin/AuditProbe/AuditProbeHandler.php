<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\AuditProbe;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a user, so a test can see that the change and the audit row stand or fall together.
 */
final class AuditProbeHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AuditProbeCommand $command): void
    {
        $this->em->persist(new AdminUser($command->id, $command->id.'@example.ch', 'Probe', '!', AdminRole::Editor));
        $this->em->flush();

        if ($command->fail) {
            throw new \RuntimeException('The probe fails on request.');
        }
    }
}
