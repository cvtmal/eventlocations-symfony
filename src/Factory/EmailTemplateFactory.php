<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\EmailTemplate;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A mail template with a unique name, using one merge field in each text.
 *
 * @extends PersistentObjectFactory<EmailTemplate>
 */
final class EmailTemplateFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return EmailTemplate::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'name' => 'Vorlage '.self::faker()->unique()->numberBetween(1, 999999),
            'subject' => 'Deine Location {venue.name}',
            'bodyMarkdown' => "Hallo {contact.first_name}\n\nFreundliche Grüsse",
        ];
    }
}
