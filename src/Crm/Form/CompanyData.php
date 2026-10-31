<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\CreateCompany;

/**
 * "Firma erfassen" (CRM-1): the few facts a company starts with; the record page does the rest. The
 * rules are `PropertyConstraints`', set by `CompanyType`, so a field refuses here what its inline
 * edit refuses.
 */
final class CompanyData
{
    public ?string $name = null;
    public ?string $website = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $owner = null;

    public function toCommand(string $id): CreateCompany
    {
        return new CreateCompany($id, (string) $this->name, $this->website, $this->phone, $this->email, $this->owner);
    }
}
