<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Entity\AdminUser;
use App\Admin\Security\AdminIdentity;
use App\Admin\ValueObject\AdminRole;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminIdentityTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_the_serialized_form_holds_neither_hash_nor_secret(): void
    {
        $serialized = serialize(AdminIdentity::fromEntity(self::user(), self::SECRET));

        self::assertStringNotContainsString('hash-of-password', $serialized);
        self::assertStringNotContainsString(self::SECRET, $serialized);
    }

    public function test_it_equals_itself_after_a_round_trip(): void
    {
        $identity = AdminIdentity::fromEntity(self::user(), self::SECRET);
        $restored = unserialize(serialize($identity));

        self::assertInstanceOf(AdminIdentity::class, $restored);
        self::assertTrue($restored->isEqualTo($identity));
        self::assertNull($restored->getPassword());
        self::assertFalse($restored->isTotpAuthenticationEnabled());
    }

    public function test_a_password_change_ends_equality(): void
    {
        $user = self::user();
        $before = AdminIdentity::fromEntity($user, self::SECRET);
        $user->changePassword('another-hash');

        self::assertFalse($before->isEqualTo(AdminIdentity::fromEntity($user, self::SECRET)));
    }

    public function test_a_role_change_ends_equality(): void
    {
        $user = self::user();
        $before = AdminIdentity::fromEntity($user, self::SECRET);
        $user->changeRole(AdminRole::Admin);

        self::assertFalse($before->isEqualTo(AdminIdentity::fromEntity($user, self::SECRET)));
    }

    public function test_a_totp_reset_ends_equality(): void
    {
        $user = self::user();
        $before = AdminIdentity::fromEntity($user, self::SECRET);
        $user->resetTotp();

        self::assertFalse($before->isEqualTo(AdminIdentity::fromEntity($user, null)));
    }

    public function test_another_kind_of_user_is_not_equal(): void
    {
        self::assertFalse(AdminIdentity::fromEntity(self::user(), null)->isEqualTo(new InMemoryUser('a@b.ch', 'x')));
    }

    public function test_roles_identifier_and_first_name(): void
    {
        $identity = AdminIdentity::fromEntity(self::user(), null);

        self::assertSame(['ROLE_EDITOR'], $identity->getRoles());
        self::assertSame('ed@example.ch', $identity->getUserIdentifier());
        self::assertSame('Eva', $identity->firstName());
    }

    public function test_totp_configuration_follows_the_secret(): void
    {
        $identity = AdminIdentity::fromEntity(self::user(), null);
        self::assertFalse($identity->isTotpAuthenticationEnabled());
        self::assertNull($identity->getTotpAuthenticationConfiguration());

        $configuration = $identity->withTotpSecret(self::SECRET)->getTotpAuthenticationConfiguration();
        self::assertNotNull($configuration);
        self::assertSame(self::SECRET, $configuration->getSecret());
        self::assertSame(TotpConfiguration::ALGORITHM_SHA1, $configuration->getAlgorithm());
        self::assertSame(30, $configuration->getPeriod());
        self::assertSame(6, $configuration->getDigits());
    }

    private static function user(): AdminUser
    {
        $user = new AdminUser('0199f0d0-0000-7000-8000-000000000001', 'Ed@Example.ch', 'Eva Editor', 'hash-of-password', AdminRole::Editor);
        $user->enrolTotp('v1:encrypted');

        return $user;
    }
}
