<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #14's re-render of the detail page, with debug on: the CSP is not keyed on the status, because
 * Symfony lifts it from its own exception page only. A 422 is an ordinary controller response.
 */
final class InquiryRerenderSeoTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    protected function setUp(): void
    {
        $this->start();
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    public function test_a_422_keeps_the_csp_and_advertises_only_the_venue(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['email' => 'kein-mail']));

        self::assertResponseStatusCodeSame(422);
        $headers = $this->client->getResponse()->headers;
        $policy = (string) $headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertCount(0, $crawler->filter('link[hreflang]'));
        self::assertSame(
            'http://localhost/hochzeitslocation/'.DetailVenues::FULL_SLUG,
            $crawler->filter('link[rel="canonical"]')->attr('href'),
        );
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertStringContainsString('nofollow', (string) $headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
    }
}
