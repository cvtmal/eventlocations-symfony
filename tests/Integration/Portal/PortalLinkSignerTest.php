<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Security\PortalLinkSigner;
use App\Tests\Support\Admin\RollsBackDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Zenstruck\Foundry\Test\Factories;

final class PortalLinkSignerTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_registration_token_gives_its_account_with_no_password_yet(): void
    {
        $user = PortalUserFactory::new()->unconfirmed()->create();

        self::assertSame($user->id(), $this->signer()->resetUserId($this->signer()->registrationToken($user)));
    }

    public function test_a_reset_token_gives_its_account(): void
    {
        $user = PortalUserFactory::createOne();

        self::assertSame($user->id(), $this->signer()->resetUserId($this->signer()->resetToken($user)));
    }

    public function test_a_token_has_the_documented_shape(): void
    {
        $user = PortalUserFactory::createOne();

        self::assertMatchesRegularExpression(
            '~^'.preg_quote($user->id(), '~').'\.\d{10}\.[A-Za-z0-9_\~-]{88}$~',
            $this->signer()->resetToken($user),
        );
    }

    public function test_a_registration_link_lasts_48_hours_and_a_reset_link_one_hour(): void
    {
        $user = PortalUserFactory::createOne();
        $before = time();

        $registration = (int) explode('.', $this->signer()->registrationToken($user))[1];
        $reset = (int) explode('.', $this->signer()->resetToken($user))[1];

        self::assertEqualsWithDelta($before + 48 * 3600, $registration, 5);
        self::assertEqualsWithDelta($before + 3600, $reset, 5);
    }

    public function test_a_past_expiry_fails(): void
    {
        $user = PortalUserFactory::createOne();

        self::assertNull($this->signer()->resetUserId($this->signer()->resetToken($user, time() - 10)));
    }

    public function test_a_longer_expiry_written_into_the_token_fails(): void
    {
        $user = PortalUserFactory::createOne();
        [$id, , $hash] = explode('.', $this->signer()->resetToken($user));

        self::assertNull($this->signer()->resetUserId($id.'.'.(time() + 999_999).'.'.$hash));
    }

    public function test_a_changed_hash_fails(): void
    {
        $user = PortalUserFactory::createOne();
        $token = $this->signer()->resetToken($user);
        $flipped = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');

        self::assertNull($this->signer()->resetUserId($flipped));
    }

    public function test_another_accounts_id_fails(): void
    {
        $token = $this->signer()->resetToken(PortalUserFactory::createOne());
        $other = PortalUserFactory::createOne();

        self::assertNull($this->signer()->resetUserId($other->id().substr($token, 36)));
    }

    public function test_an_unknown_account_fails(): void
    {
        $token = $this->signer()->resetToken(PortalUserFactory::createOne());

        self::assertNull($this->signer()->resetUserId('00000000-0000-7000-8000-000000000000'.substr($token, 36)));
    }

    public function test_a_reset_token_dies_with_the_password_it_signed(): void
    {
        $user = PortalUserFactory::createOne();
        $token = $this->signer()->resetToken($user);

        $user->changePassword('another-hash');

        self::assertNull($this->signer()->resetUserId($token));
    }

    public function test_a_reset_token_of_a_deactivated_account_fails(): void
    {
        $user = PortalUserFactory::createOne();
        $token = $this->signer()->resetToken($user);

        $user->deactivate();

        self::assertNull($this->signer()->resetUserId($token));
    }

    public function test_every_link_of_an_account_with_no_password_dies_once_one_is_set(): void
    {
        $user = PortalUserFactory::new()->unconfirmed()->create();
        $registration = $this->signer()->registrationToken($user);
        $reset = $this->signer()->resetToken($user);

        $user->setPasswordFromMail('a-hash', new \DateTimeImmutable());

        self::assertNull($this->signer()->resetUserId($registration));
        self::assertNull($this->signer()->resetUserId($reset));
    }

    public function test_a_registration_token_of_a_deactivated_account_fails(): void
    {
        $user = PortalUserFactory::new()->unconfirmed()->create();
        $token = $this->signer()->registrationToken($user);

        $user->deactivate();

        self::assertNull($this->signer()->resetUserId($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        $hash = str_repeat('a', 88);
        $id = '019a0000-0000-7000-8000-000000000000';

        yield 'empty' => [''];
        yield 'garbage' => ['nonsense'];
        yield 'not a uuid' => ['not-a-uuid.1893456000.'.$hash];
        yield 'right length, not a uuid' => [str_repeat('-', 36).'.1893456000.'.$hash];
        yield 'capital letters' => [strtoupper($id).'.1893456000.'.$hash];
        yield 'no expiry' => [$id.'..'.$hash];
        yield 'expiry too long' => [$id.'.1234567890123.'.$hash];
        yield 'short hash' => [$id.'.1893456000.abc'];
        yield 'character outside the alphabet' => [$id.'.1893456000.'.substr($hash, 1).'='];
        yield 'trailing newline' => [$id.'.1893456000.'.$hash."\n"];
        yield 'nul' => [$id.".1893456000.\0".substr($hash, 1)];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_token_fails_without_a_query(string $token): void
    {
        $users = $this->createMock(PortalUserRepository::class);
        $users->expects(self::never())->method('find');
        $signer = new PortalLinkSigner(PropertyAccess::createPropertyAccessor(), 'a-secret', $users);

        self::assertNull($signer->resetUserId($token));
    }

    private function signer(): PortalLinkSigner
    {
        $signer = self::getContainer()->get(PortalLinkSigner::class);
        self::assertInstanceOf(PortalLinkSigner::class, $signer);

        return $signer;
    }
}
