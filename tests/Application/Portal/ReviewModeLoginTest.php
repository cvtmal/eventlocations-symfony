<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\VenueFactory;
use App\Shared\Security\ReviewMode;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Portal\ReviewAccounts;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/** review-demo: `admin` / `admin` opens /konto in review mode only, as the manager of the demo's Pro location. */
final class ReviewModeLoginTest extends WebTestCase
{
    use Factories;
    use PortalSession;
    use ReviewAccounts;

    public function test_without_review_mode_admin_admin_is_refused(): void
    {
        $this->seedReviewAccounts();

        $this->submitManagerLogin(ReviewMode::LOGIN, ReviewMode::PASSWORD);

        self::assertResponseRedirects('/konto/anmelden');
        $this->client->followRedirect();
        self::assertSelectorExists('#login-email[type="email"]');
    }

    public function test_in_review_mode_admin_admin_opens_the_account_with_the_pro_location(): void
    {
        $this->switchReviewModeOn();
        VenueFactory::createOne(['name' => 'Schloss Review', 'tier' => KnownTiers::PRO]);
        $this->seedReviewAccounts();

        $this->client->request('GET', '/konto/anmelden');
        self::assertSelectorExists('#login-email[type="text"]');
        $this->client->submitForm('Anmelden', ['_username' => ReviewMode::LOGIN, '_password' => ReviewMode::PASSWORD]);

        self::assertResponseRedirects('/konto');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.venue-widget__name', 'Schloss Review');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }
}
