<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Admin\Entity\AdminUser;
use App\Crm\Command\SendCrmEmail;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityContact;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactOfAnotherCompany;
use App\Crm\Exception\NoEmailAddress;
use App\Crm\Mailer\CrmMailer;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\EmailStatus;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Sends a staff member's mail and writes its timeline entry, in one command on purpose: `DeliverInquiry` commits its row
 * before SMTP so that an inquiry survives, but here staff still hold the text on screen and nothing is lost. The accepted
 * cost: a commit that fails after SMTP accepted the mail leaves a mail with no entry and a 500. Staff may send again.
 *
 * A mail failure is an outcome, not an error: staff see it at once and the entry commits as `failed`. The mailer unwraps
 * only transport errors, so anything else that fails in its pipeline is a `HandlerFailedException`, and `Address` refuses
 * some values at build time. Any other error bubbles, and nothing is stored.
 *
 * The record is locked as in `LogActivityHandler`, so an erasure either sees the new link and anonymises the entry, or
 * this handler sees the contact gone. `to` stays the real address under the override, so the entry says what staff asked
 * for. Log text carries the entry and where the cause was raised, never its message, which can quote an address.
 */
final class SendCrmEmailHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly CrmMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendCrmEmail $command): void
    {
        [$address, $company, $contact] = match ($command->kind) {
            RecordKind::Company => $this->companyTarget($command->recordId, $command->contactId),
            RecordKind::Contact => $this->contactTarget($command->recordId),
            RecordKind::Deal => throw new \LogicException('A deal has no mail step, the API refuses it.'),
        };
        $type = $this->em->find(ActivityType::class, ActivityType::EMAIL_SENT) ?? throw new \LogicException('The migration seeds this type.');
        $actor = $this->em->find(AdminUser::class, $command->actingUserId) ?? throw new \LogicException('Only a logged-in staff member sends.');

        $status = $this->send($command, $address, $actor);

        $activity = new Activity(
            $command->id,
            $type,
            $this->clock->now(),
            $actor,
            $command->subject,
            $command->body,
            ['to' => $address, ...(null === $command->cc ? [] : ['cc' => $command->cc]), 'status' => $status->value],
        );

        $this->em->persist($activity);
        if (null !== $contact) {
            $this->em->persist(new ActivityContact($activity, $contact));
        }
        if (null !== $company) {
            $this->em->persist(new ActivityCompany($activity, $company));
        }
    }

    /**
     * @return array{string, Company, ?Contact}
     */
    private function companyTarget(string $companyId, ?string $contactId): array
    {
        $company = $this->companies->lock($companyId);
        if (null === $contactId) {
            return [$company->email() ?? throw NoEmailAddress::forCompany($companyId), $company, null];
        }

        $contact = $this->contacts->lock($contactId);
        if ($contact->company()?->id() !== $company->id()) {
            throw ContactOfAnotherCompany::forContact($contactId);
        }

        return [$contact->email() ?? throw NoEmailAddress::forContact($contactId), $company, $contact];
    }

    /**
     * @return array{string, ?Company, Contact}
     */
    private function contactTarget(string $contactId): array
    {
        $contact = $this->contacts->lock($contactId);

        return [$contact->email() ?? throw NoEmailAddress::forContact($contactId), $contact->company(), $contact];
    }

    private function send(SendCrmEmail $command, string $address, AdminUser $actor): EmailStatus
    {
        try {
            $this->mailer->send($address, $command->cc, $command->subject, $command->body, $actor->displayName());
        } catch (TransportExceptionInterface|RfcComplianceException|HandlerFailedException $exception) {
            $this->logFailure($command->id, $exception);

            return EmailStatus::Failed;
        }

        // The one sign that a mail left, should the commit after it fail and leave no entry.
        $this->logger->info('Crm mail {activity} accepted by SMTP.', ['activity' => $command->id]);

        return EmailStatus::Sent;
    }

    private function logFailure(string $activityId, \Throwable $exception): void
    {
        $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
        $this->logger->error('Crm mail {activity} failed ({exception_class} at {file}:{line}).', [
            'activity' => $activityId,
            'exception_class' => $cause::class,
            'file' => $cause->getFile(),
            'line' => $cause->getLine(),
        ]);
    }
}
