<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Admin\Entity\AdminUser;
use App\Crm\Command\CreateTask;
use App\Crm\Entity\Task;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the task. Only an active account can get a new task (`OwnerResolver`). The record is locked, so an erasure
 * either sees the task and takes it along, or this handler sees the record gone. #28's `deal` case joins the `match`.
 */
final class CreateTaskHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly OwnerResolver $owners,
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CreateTask $command): void
    {
        $title = PropertyValue::canonical($command->title) ?? throw InvalidPropertyValue::required('title');
        $assignee = $this->owners->resolve($command->assigneeId) ?? throw InvalidPropertyValue::required('owner');

        [$company, $contact] = match ($command->kind) {
            null => [null, null],
            RecordKind::Company => [$this->companies->lock((string) $command->recordId), null],
            RecordKind::Contact => [null, $this->contacts->lock((string) $command->recordId)],
        };

        $this->em->persist(new Task(
            $command->id,
            $title,
            $command->dueOn,
            $assignee,
            $this->em->getReference(AdminUser::class, $command->actingUserId),
            $company,
            $contact,
        ));
    }
}
