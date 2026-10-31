<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityContact;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;
use function Zenstruck\Foundry\Persistence\persist;
use function Zenstruck\Foundry\Persistence\repository;

/**
 * A note without author, on no timeline until a state links it. The types are the migration's
 * seeded rows, looked up, never created.
 *
 * @extends PersistentObjectFactory<Activity>
 */
final class ActivityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Activity::class;
    }

    public function ofType(string $code): self
    {
        return $this->with(['type' => lazy(static fn () => self::type($code))]);
    }

    public function onCompany(Company $company): self
    {
        return $this->afterPersist(static function (Activity $activity) use ($company): void {
            persist(ActivityCompany::class, ['activity' => $activity, 'company' => $company]);
        });
    }

    public function onContact(Contact $contact): self
    {
        return $this->afterPersist(static function (Activity $activity) use ($contact): void {
            persist(ActivityContact::class, ['activity' => $activity, 'contact' => $contact]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'type' => lazy(static fn () => self::type(ActivityType::NOTE)),
            'occurredAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-90 days', 'now'))->setTime((int) self::faker()->numberBetween(8, 18), 0),
            'actor' => null,
            'title' => null,
            'body' => self::faker()->realText(160),
            'payload' => [],
        ];
    }

    private static function type(string $code): ActivityType
    {
        return repository(ActivityType::class)->find($code) ?? throw new \LogicException(\sprintf('There is no activity type "%s".', $code));
    }
}
