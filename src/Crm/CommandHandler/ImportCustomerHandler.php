<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ImportCustomer;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityContact;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\CustomerImportOutcome;
use App\Crm\ValueObject\CustomerRecord;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One customer of the owner's sheet in one transaction (#48 R7, R8): a manual prospect without an
 * owner, its contact, and one logged mail per date linked to both. Nobody wrote the mails here, so they
 * have no actor. The name's advisory lock and the folded-name check under it keep a rerun or a second
 * run from creating the company twice; the runner's classification already skips known names, so this
 * is the race guard. A taken address is left out of the contact; under concurrency the unique key
 * decides, mapped to ContactEmailTaken by flushing here, so the row is reported as failed and a second
 * run leaves the address out.
 */
final class ImportCustomerHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(ImportCustomer $command): CustomerImportOutcome
    {
        $record = $command->record;
        $this->companies->lockFoldedName($record->name);
        if ($this->companies->foldedNameExists($record->name)) {
            return CustomerImportOutcome::skipped();
        }

        $email = ContactEmail::normalise($record->email);
        $company = new Company($command->companyId, $record->name, CompanyOrigin::Manual);
        $company->changeWebsite($record->website);
        $company->changePhone($record->phone);
        $company->changeEmail($email);
        $company->changeNotes($this->notes($record));
        $this->em->persist($company);

        $emailTaken = null !== $email && $this->contacts->emailTaken($email);
        $contactEmail = $emailTaken ? null : $email;
        $contact = new Contact(EntityId::generate(), $record->firstName, $record->lastName, ContactKind::VenueContact, $company, $contactEmail);
        $this->em->persist($contact);

        $type = $this->em->getReference(ActivityType::class, ActivityType::EMAIL_LOGGED) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.');
        $body = $this->translator->trans('customer_import.mail_body', [], 'crm', $this->locales->default());
        foreach ($record->mailDates as $date) {
            $activity = new Activity(EntityId::generate(), $type, $date, null, null, $body);
            $this->em->persist($activity);
            $this->em->persist(new ActivityCompany($activity, $company));
            $this->em->persist(new ActivityContact($activity, $contact));
        }

        if (null !== $contactEmail) {
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw ContactEmailTaken::create();
            }
        }

        return CustomerImportOutcome::created($company->id(), $emailTaken, \count($record->mailDates));
    }

    /**
     * One block per sheet row (R8): the heading, then a `Label: value` line per kept field, blocks
     * apart by one blank line. The row goes in as a string, so ICU does not group 1234 as "1.234".
     */
    private function notes(CustomerRecord $record): ?string
    {
        $locale = $this->locales->default();
        $blocks = [];
        foreach ($record->noteBlocks as $block) {
            $lines = [$this->translator->trans('customer_import.note.heading', ['row' => (string) $block['row']], 'crm', $locale)];
            foreach ($block['lines'] as [$field, $value]) {
                $lines[] = $this->translator->trans($field->key(), [], 'crm', $locale).': '.$value;
            }
            $blocks[] = implode("\n", $lines);
        }

        return [] === $blocks ? null : implode("\n\n", $blocks);
    }
}
