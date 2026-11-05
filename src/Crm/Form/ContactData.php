<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\ChangeContact;
use App\Crm\Command\CreateContact;
use App\Crm\ReadModel\CompanyRecordContact;
use App\Crm\ValueObject\PropertyValue;

/**
 * The add and edit dialogs of a Location's contact card (CRM-2, #55): name, position and the reachability facts. The
 * rules are `PropertyConstraints::contact()`'s, set by `ContactType`.
 */
final class ContactData
{
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $position = null;
    public ?string $email = null;
    public ?string $phone = null;

    public static function fromRecord(CompanyRecordContact $contact): self
    {
        $data = new self();
        $data->firstName = $contact->firstName;
        $data->lastName = $contact->lastName;
        $data->position = $contact->position;
        $data->email = $contact->email;
        $data->phone = $contact->phone;

        return $data;
    }

    public function toCreate(string $id, string $companyId): CreateContact
    {
        return new CreateContact($id, $companyId, $this->firstName, (string) $this->lastName, $this->email, $this->phone, $this->position);
    }

    /**
     * Null when the dialog changes nothing (the no-op rule): an unchanged save leaves no audit row. Values are compared
     * as the handler stores them.
     */
    public function toChange(string $companyId, CompanyRecordContact $current): ?ChangeContact
    {
        $fields = array_keys(array_filter(
            [
                'first_name' => PropertyValue::canonical($this->firstName) !== $current->firstName,
                'last_name' => PropertyValue::canonical($this->lastName) !== $current->lastName,
                'email' => PropertyValue::canonical($this->email, email: true) !== $current->email,
                'phone' => PropertyValue::canonical($this->phone) !== $current->phone,
                'position' => PropertyValue::canonical($this->position) !== $current->position,
            ],
        ));
        if ([] === $fields) {
            return null;
        }

        return new ChangeContact($current->id, $companyId, $this->firstName, (string) $this->lastName, $this->email, $this->phone, $this->position, $fields);
    }
}
