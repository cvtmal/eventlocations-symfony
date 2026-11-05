<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Contact;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A contact row with a unique e-mail on a new company (Location), unless a state names one (#55).
 *
 * @extends PersistentObjectFactory<Contact>
 */
final class ContactFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Contact::class;
    }

    public function withDetails(): self
    {
        return $this->afterInstantiate(static function (Contact $contact): void {
            $contact->changePhone(self::faker()->numerify('+41 79 ### ## ##'));
            $contact->changePosition(self::faker()->randomElement(['Inhaberin', 'Geschäftsführer', 'Eventmanagerin', 'Bankettleiter']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'company' => CompanyFactory::new(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'email' => self::faker()->unique()->safeEmail(),
        ];
    }
}
