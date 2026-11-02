<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\DeleteEmailTemplate;
use App\Crm\Repository\EmailTemplateRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteEmailTemplateHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(DeleteEmailTemplate $command): void
    {
        $this->em->remove($this->templates->get($command->id));
    }
}
