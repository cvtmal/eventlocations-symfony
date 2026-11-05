<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * One choice in the composer's An field. A null `contactId` is the company's own address.
 */
final readonly class EmailRecipient
{
    public function __construct(
        public ?string $contactId,
        public string $name,
        public string $email,
    ) {
    }

    /**
     * The company's address first, then its contacts that have one, in the Kontakte card's order.
     *
     * @return list<self>
     */
    public static function ofCompany(CompanyRecord $company): array
    {
        $recipients = null === $company->email ? [] : [new self(null, $company->name, $company->email)];
        foreach ($company->contacts as $contact) {
            if (null !== $contact->email) {
                $recipients[] = new self($contact->id, $contact->fullName(), $contact->email);
            }
        }

        return $recipients;
    }
}
