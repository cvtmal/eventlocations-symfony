<?php

declare(strict_types=1);

namespace App\Factory;

use App\Portal\Entity\Submission;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A draft for a new venue. Needs imported towns, as VenueFactory does, unless a `venue` attribute is passed.
 * The states walk the entity's own methods, each with a fresh account.
 *
 * @extends PersistentObjectFactory<Submission>
 */
final class SubmissionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Submission::class;
    }

    public function submitted(): self
    {
        return $this->afterInstantiate(static function (Submission $submission): void {
            $submission->submit(PortalUserFactory::createOne(), new \DateTimeImmutable());
        });
    }

    public function rejected(): self
    {
        return $this->submitted()->afterInstantiate(static function (Submission $submission): void {
            $submission->reject(AdminUserFactory::createOne(), 'Die Angaben reichen nicht aus.', new \DateTimeImmutable());
        });
    }

    public function accepted(): self
    {
        return $this->submitted()->afterInstantiate(static function (Submission $submission): void {
            $submission->accept(AdminUserFactory::createOne(), new \DateTimeImmutable());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'now' => new \DateTimeImmutable(),
        ];
    }
}
