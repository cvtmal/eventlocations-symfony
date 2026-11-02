<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateInvoiceTask;
use App\Crm\Entity\Task;
use App\Crm\Exception\NoTaskAssignee;
use App\Crm\Repository\CompanyRepository;
use App\Crm\TaskAssignee;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Nobody wrote it, so it has no `createdBy`, like a renewal task. It hangs on the company, so no deal owner applies:
 * the assignee is the company's owner, else the oldest admin. Whether it is due at all is `NewVenuePublished`'s call: this writes.
 */
final class CreateInvoiceTaskHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly TaskAssignee $assignees,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(CreateInvoiceTask $command): void
    {
        $company = $this->companies->lock($command->companyId);

        $this->em->persist(new Task(
            $command->id,
            $this->translator->trans('task.invoice_title', [], 'crm', $this->locales->default()),
            TaskDueDate::daysAfter($this->clock->now(), CreateInvoiceTask::DUE_AFTER_DAYS),
            $this->assignees->among($company->owner()) ?? throw NoTaskAssignee::forCompany($company->id()),
            null,
            company: $company,
        ));
    }
}
