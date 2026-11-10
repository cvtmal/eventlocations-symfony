<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\TotpSecretCipher;
use App\Shared\Security\ReviewMode;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Portal\ReviewAccounts;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * review-demo: `admin` / `admin` and no 2FA exist only in review mode. Without the flag (prod, dev, test) the seeded
 * account is an ordinary staff account: `admin` is no login name and the TOTP step stays.
 */
final class ReviewModeLoginTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ReviewAccounts;

    public function test_without_review_mode_admin_admin_is_refused(): void
    {
        $this->seedReviewAccounts();

        $this->submitPassword(ReviewMode::LOGIN, ReviewMode::PASSWORD);

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort ist falsch.');
        self::assertSelectorExists('#login-email[type="email"]');
    }

    public function test_without_review_mode_the_review_admin_is_still_challenged_for_a_code(): void
    {
        $this->seedReviewAccounts();
        $this->enrolReviewAdmin();

        $this->submitPassword(ReviewMode::ADMIN_EMAIL, ReviewMode::PASSWORD);
        $this->client->followRedirect();

        self::assertResponseRedirects('/admin/2fa');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
    }

    public function test_without_review_mode_the_review_admin_must_enrol_first(): void
    {
        $this->seedReviewAccounts();

        $this->submitPassword(ReviewMode::ADMIN_EMAIL, ReviewMode::PASSWORD);
        $this->client->followRedirect();

        self::assertResponseRedirects('/admin/2fa/einrichten');
    }

    public function test_in_review_mode_admin_admin_opens_the_dashboard_without_a_code(): void
    {
        $this->switchReviewModeOn();
        $this->seedReviewAccounts();

        $this->client->request('GET', '/admin/login');
        self::assertSelectorExists('#login-email[type="text"]');
        $this->client->submitForm('Weiter', ['_username' => ReviewMode::LOGIN, '_password' => ReviewMode::PASSWORD]);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Guten Tag, Review');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_in_review_mode_an_enrolled_account_is_not_asked_for_a_code_either(): void
    {
        $this->switchReviewModeOn();
        $this->seedReviewAccounts();
        $this->enrolReviewAdmin();

        $this->submitPassword(ReviewMode::LOGIN, ReviewMode::PASSWORD);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function test_in_review_mode_other_accounts_still_log_in_with_their_address(): void
    {
        $this->switchReviewModeOn();
        $staff = $this->createStaff();

        $this->submitPassword($staff['user']->email());

        self::assertResponseRedirects('/admin');
    }

    private function enrolReviewAdmin(): void
    {
        $admin = self::getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $admin);
        $user = $admin->findOneByEmail(ReviewMode::ADMIN_EMAIL);
        self::assertInstanceOf(AdminUser::class, $user);
        $totp = self::getContainer()->get(TotpAuthenticatorInterface::class);
        self::assertInstanceOf(TotpAuthenticatorInterface::class, $totp);
        $cipher = self::getContainer()->get(TotpSecretCipher::class);
        self::assertInstanceOf(TotpSecretCipher::class, $cipher);

        $user->enrolTotp($cipher->encrypt($totp->generateSecret()));
        $this->entityManager()->flush();
    }
}
