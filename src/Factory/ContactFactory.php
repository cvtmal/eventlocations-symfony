<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Contact;
use App\Crm\ValueObject\ContactKind;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A contact of kind `other` with a unique e-mail, no company and no owner.
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
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'kind' => ContactKind::Other,
            'company' => null,
            'email' => self::faker()->unique()->safeEmail(),
            'owner' => null,
        ];
    }
}
