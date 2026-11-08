<?php

declare(strict_types=1);

namespace App\Factory;

use App\Shared\Uid\EntityId;
use App\Venues\Entity\ModerationCase;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;

/**
 * An open complaint of the public form (G2): a correction about a location the site does not know, with a fictional
 * complainant. Rows only: nothing is purged or mailed. `decided()` closes it as staff would; the photos it names are
 * `ModerationCasePhotoFactory` rows (an open case naming a photo hides it).
 *
 * @extends PersistentObjectFactory<ModerationCase>
 */
final class ModerationCaseFactory extends PersistentObjectFactory
{
    public const string STAFF = '019a0000-0000-7000-8000-0000000000de';

    public static function class(): string
    {
        return ModerationCase::class;
    }

    public function decided(ModerationDecision $decision = ModerationDecision::Keep, string $by = self::STAFF, ?string $note = null): self
    {
        return $this->afterInstantiate(static function (ModerationCase $case) use ($decision, $by, $note): void {
            $case->decide($decision, $note, $by, new \DateTimeImmutable());
        });
    }

    public function tookEntryOffline(): self
    {
        return $this->afterInstantiate(static function (ModerationCase $case): void {
            $case->recordEntryTakenOffline(new \DateTimeImmutable());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => lazy(static fn (): string => EntityId::generate()),
            'kind' => ModerationKind::Correction,
            'venue' => null,
            'venueReference' => 'Gasthaus Beispiel, Musterdorf',
            'complainantName' => 'Erika Beispiel',
            'complainantEmail' => 'erika.beispiel@example.ch',
            'message' => 'Die Öffnungszeiten auf dem Eintrag stimmen nicht mehr.',
            'sourceUrl' => null,
            'at' => new \DateTimeImmutable(),
        ];
    }
}
