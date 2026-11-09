<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Form\PropertyConstraints;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\ContactProperty;
use App\Crm\ValueObject\OutreachCohort;
use App\Shared\Csv\SheetFile;
use App\Shared\Funnel\CampaignCode;
use App\Venues\ReadModel\VenueKeyQuery;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The owner's contact list (F14, D49), one Location per row: its name, postal address, e-mail, phone and website,
 * one contact person, the outreach fields of the "Akquise" tab and, optionally, the venue it runs (number or content
 * key). `app:crm:import-contacts` reads it; the template is tests/Fixtures/Onboarding/contacts.csv.
 *
 * check() applies the rules of the CRM's create form and Akquise tab (`PropertyConstraints`, the outreach codes, a
 * campaign only for letters) and answers reason codes, never a value.
 */
final readonly class ContactSheet
{
    public const array HEADER = [
        'location', 'address', 'email', 'phone', 'website',
        'first_name', 'last_name', 'position', 'contact_email', 'contact_phone',
        'cohort', 'channel', 'basis', 'campaign', 'venue',
    ];

    private const array LOCATION_COLUMNS = [
        'location' => CompanyProperty::Name,
        'address' => CompanyProperty::Address,
        'email' => CompanyProperty::Email,
        'phone' => CompanyProperty::Phone,
        'website' => CompanyProperty::Website,
    ];

    private const array PERSON_COLUMNS = [
        'first_name' => ContactProperty::FirstName,
        'last_name' => ContactProperty::LastName,
        'position' => ContactProperty::Position,
        'contact_email' => ContactProperty::Email,
        'contact_phone' => ContactProperty::Phone,
    ];

    public function __construct(
        private ValidatorInterface $validator,
        private VenueKeyQuery $venues,
    ) {
    }

    /**
     * @return list<ContactSheetRow>
     *
     * @throws \RuntimeException when the file is missing or its header is not the sheet's
     */
    public static function read(string $file): array
    {
        return array_map(
            static fn (array $row): ContactSheetRow => new ContactSheetRow($row['line'], $row['cells']),
            SheetFile::read($file, self::HEADER, 'contact sheet'),
        );
    }

    /**
     * @return array{errors: list<string>, venueNumber: ?int} errors as "row N: <column>: <reason code>"
     */
    public function check(ContactSheetRow $row): array
    {
        $errors = [];
        foreach (self::LOCATION_COLUMNS as $column => $property) {
            $errors = [...$errors, ...$this->violations($row->value($column), PropertyConstraints::company($property), $column)];
        }
        if ($row->hasContact()) {
            foreach (self::PERSON_COLUMNS as $column => $property) {
                $errors = [...$errors, ...$this->violations($row->value($column), PropertyConstraints::contact($property), $column)];
            }
        }

        $cohort = $row->value('cohort');
        if (null !== $cohort && null === OutreachCohort::tryFrom(strtoupper($cohort))) {
            $errors[] = 'cohort: unknown';
        }
        $channel = $row->value('channel');
        if (null !== $channel && null === ContactChannel::tryFrom($channel)) {
            $errors[] = 'channel: unknown';
        }
        $basis = $row->value('basis');
        if (null !== $basis && null === ContactBasis::tryFrom($basis)) {
            $errors[] = 'basis: unknown';
        }
        $campaign = $row->value('campaign');
        if (null !== $campaign && !CampaignCode::isValid($campaign)) {
            $errors[] = 'campaign: crm.outreach.campaign_code';
        } elseif (null !== $campaign && null !== $channel && ContactChannel::Postal->value !== $channel) {
            // A campaign code goes on postal links only (PO decision, OutreachData).
            $errors[] = 'campaign: crm.outreach.campaign_not_postal';
        }

        $venueNumber = null;
        $venue = $row->value('venue');
        if (null !== $venue) {
            $venueNumber = ctype_digit($venue) ? $this->venues->find((int) $venue)?->number : $this->venues->numberOf($venue);
            if (null === $venueNumber) {
                $errors[] = 'venue: unknown';
            }
        }

        return [
            'errors' => array_map(static fn (string $error): string => \sprintf('row %d: %s', $row->line, $error), $errors),
            'venueNumber' => $venueNumber,
        ];
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<string>
     */
    private function violations(?string $value, array $constraints, string $column): array
    {
        $errors = [];
        foreach ($this->validator->validate($value, $constraints) as $violation) {
            $errors[] = $column.': '.$violation->getMessageTemplate();
        }

        return array_values(array_unique($errors));
    }
}
