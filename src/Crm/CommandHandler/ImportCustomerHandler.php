<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ImportCustomer;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\CustomerImportOutcome;
use App\Crm\ValueObject\CustomerRecord;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One customer of the owner's sheet in one transaction (#48 R7, R8): a manual prospect without an
 * owner, its contact row, and one logged mail per date on the company. Nobody wrote the mails here, so
 * they have no actor. The name's advisory lock and the folded-name check under it keep a rerun or a
 * second run from creating the company twice; the runner's classification already skips known names,
 * so this is the race guard. The contact is the first row of a brand-new Location, so its address can
 * never be taken (the unique key is per Location, #55).
 */
final class ImportCustomerHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
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

        $this->em->persist(new Contact(EntityId::generate(), $company, $record->firstName, $record->lastName, $email));

        $type = $this->em->getReference(ActivityType::class, ActivityType::EMAIL_LOGGED) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.');
        $body = $this->translator->trans('customer_import.mail_body', [], 'crm', $this->locales->default());
        foreach ($record->mailDates as $date) {
            $activity = new Activity(EntityId::generate(), $type, $date, null, null, $body);
            $this->em->persist($activity);
            $this->em->persist(new ActivityCompany($activity, $company));
        }

        return CustomerImportOutcome::created($company->id(), \count($record->mailDates));
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
