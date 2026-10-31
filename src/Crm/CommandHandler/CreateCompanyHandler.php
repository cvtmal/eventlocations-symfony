<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateCompany;
use App\Crm\Entity\Company;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\OwnerResolver;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

final class CreateCompanyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OwnerResolver $owners,
    ) {
    }

    public function __invoke(CreateCompany $command): void
    {
        $name = PropertyValue::canonical($command->name) ?? throw InvalidPropertyValue::required('name');

        $company = new Company($command->id, $name, CompanyOrigin::Manual, $this->owners->resolve(PropertyValue::canonical($command->ownerId)));
        $company->changeWebsite(PropertyValue::canonical($command->website));
        $company->changePhone(PropertyValue::canonical($command->phone));
        $company->changeEmail(PropertyValue::canonical($command->email, email: true));
        $this->em->persist($company);
    }
}
