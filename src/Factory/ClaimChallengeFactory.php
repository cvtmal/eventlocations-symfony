<?php

declare(strict_types=1);

namespace App\Factory;

use App\Portal\Entity\ClaimChallenge;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A claim's code (G6, `claim_challenges`), open for 30 minutes from `at`. `codeHash` must come from
 * `Portal\Security\ClaimCode::hash($id, $code)` for a code a test types; the default matches no code.
 *
 * @extends PersistentObjectFactory<ClaimChallenge>
 */
final class ClaimChallengeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ClaimChallenge::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'venue' => VenueFactory::new(),
            'email' => 'info@remise-am-bach.example',
            'firstName' => 'Rita',
            'lastName' => 'Bach',
            'codeHash' => str_repeat('0', 64),
            'campaignCode' => null,
            'at' => new \DateTimeImmutable('@'.time()),
        ];
    }
}
