<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\Entity\PortalUser;
use App\Portal\Security\PortalIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class PortalIdentityTest extends TestCase
{
    public function test_the_serialized_form_holds_no_hash(): void
    {
        self::assertStringNotContainsString('hash-of-password', serialize(PortalIdentity::fromEntity(self::user())));
    }

    public function test_it_equals_itself_after_a_round_trip(): void
    {
        $identity = PortalIdentity::fromEntity(self::user());
        $restored = unserialize(serialize($identity));

        self::assertInstanceOf(PortalIdentity::class, $restored);
        self::assertTrue($restored->isEqualTo($identity));
        self::assertNull($restored->getPassword());
    }

    public function test_a_password_change_ends_equality(): void
    {
        $user = self::user();
        $before = PortalIdentity::fromEntity($user);
        $user->changePassword('another-hash');

        self::assertFalse($before->isEqualTo(PortalIdentity::fromEntity($user)));
    }

    public function test_another_kind_of_user_is_not_equal(): void
    {
        self::assertFalse(PortalIdentity::fromEntity(self::user())->isEqualTo(new InMemoryUser('a@b.ch', 'x')));
    }

    public function test_roles_identifier_and_names(): void
    {
        $identity = PortalIdentity::fromEntity(self::user());

        self::assertSame(['ROLE_PORTAL_USER'], $identity->getRoles());
        self::assertSame('andrea@example.ch', $identity->getUserIdentifier());
        self::assertSame('Andrea Keller', $identity->displayName());
        self::assertSame('AK', $identity->initials());
    }

    public function test_it_carries_the_entity_state_a_login_is_refused_on(): void
    {
        $user = self::user();
        self::assertTrue(PortalIdentity::fromEntity($user)->isActive());

        $user->deactivate();
        self::assertFalse(PortalIdentity::fromEntity($user)->isActive());
    }

    public function test_an_account_with_no_password_has_an_identity_without_one(): void
    {
        $identity = PortalIdentity::fromEntity(new PortalUser('0199f0d0-0000-7000-8000-000000000002', 'neu@example.ch', 'Neu', 'Konto'));

        self::assertNull($identity->getPassword());
    }

    private static function user(): PortalUser
    {
        $user = new PortalUser('0199f0d0-0000-7000-8000-000000000001', 'Andrea@Example.ch', 'Andrea', 'Keller');
        $user->changePassword('hash-of-password');

        return $user;
    }
}
