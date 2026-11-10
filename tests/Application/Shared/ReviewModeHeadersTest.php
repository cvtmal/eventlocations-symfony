<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Shared\Security\ReviewMode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** review-demo: in review mode no response may be indexed, public pages and robots.txt included. */
final class ReviewModeHeadersTest extends WebTestCase
{
    public function test_review_mode_marks_public_pages_noindex(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(ReviewMode::class, new ReviewMode(true));

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        $client->request('GET', '/robots.txt');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSame("User-agent: *\nDisallow: /\n", $client->getResponse()->getContent());
    }

    public function test_without_review_mode_the_home_page_stays_indexable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // The test kernel runs in debug, where Symfony itself sends a bare `noindex`; production sends none.
        self::assertNotSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
    }
}
