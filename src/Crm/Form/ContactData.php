<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\CreateContact;
use App\Crm\ValueObject\ContactKind;

/**
 * "Kontakt erfassen" (CRM-2): name, kind, company and the reachability facts; the record page does the
 * rest. `company` is a company id, preset by the company page's "+ Hinzufügen" (`?company=<id>`).
 */
final class ContactData
{
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?ContactKind $kind = ContactKind::VenueContact;
    public ?string $company = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $position = null;
    public ?string $owner = null;

    public function toCommand(string $id): CreateContact
    {
        return new CreateContact(
            $id,
            $this->firstName,
            (string) $this->lastName,
            $this->kind ?? throw new \LogicException('Validate the form before building its command.'),
            $this->company,
            $this->email,
            $this->phone,
            $this->position,
            $this->owner,
        );
    }
}
