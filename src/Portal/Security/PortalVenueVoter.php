<?php

declare(strict_types=1);

namespace App\Portal\Security;

use App\Portal\ReadModel\PortalVenueAccessQuery;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Lets a manager at a venue they hold `active` access to. The subject is the venue's number: `#[IsGranted]` hands
 * over the raw route string, a direct `isGranted()` call may pass an int.
 *
 * @extends Voter<string, int|string>
 */
final class PortalVenueVoter extends Voter
{
    public const string EDIT = 'PORTAL_VENUE_EDIT';

    public function __construct(
        private readonly PortalVenueAccessQuery $access,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && (\is_int($subject) || (\is_string($subject) && ctype_digit($subject)));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof PortalIdentity && $this->access->isActive($user->id(), (int) $subject);
    }
}
