<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Geo\ValueObject\Canton;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * #46, owner answer F: no "Kanton" before Liechtenstein. Each of the 12 messages that put
 * "Kanton" before `{canton}` selects on `{canton_code}` (lead ruling L2), so a label change or a
 * second language cannot bring "Kanton Liechtenstein" back. A call site that forgets the code
 * renders a literal `{canton_code}`, so every call site has its own Liechtenstein assertion:
 * - Seo\ListingMeta (landing titles, description): LandingPageTest, LandingPageCatalogueTest
 * - Seo\Extension\LandingPageCatalogue (heading, intro): LandingPageCatalogueTest
 * - templates/search/listing.html.twig (heading, intro): LandingPageTest
 * - Search\Twig\ListingExtension (breadcrumb.canton): LandingPageTest
 * - Seo\VenueMeta (venue.title_canton): VenueMetaTest, VenueDetailControllerTest
 * - Seo\VenueJsonLd (venue.canton_area): VenueJsonLdTest
 * - templates/seo/llms.txt.twig (venue.canton_area): LlmsTxtTest
 * - templates/seo/venue.md.twig (venue.canton_area): VenueMarkdownTest
 * - Venues\ValueObject\VenuePlace (place.canton): ListingControllerTest (card), VenueDetailControllerTest (head)
 * - templates/venues/detail/_actions.html.twig (share_text_canton): VenueDetailControllerTest.
 */
final class CantonWordingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        foreach (['title_count', 'title', 'title_short', 'title_page', 'description'] as $key) {
            yield 'seo landing.canton.'.$key => ['seo', 'landing.canton.'.$key];
        }
        yield 'seo venue.title_canton' => ['seo', 'venue.title_canton'];
        yield 'seo venue.canton_area' => ['seo', 'venue.canton_area'];
        yield 'seo breadcrumb.canton' => ['seo', 'breadcrumb.canton'];
        yield 'search landing.canton.heading' => ['search', 'landing.canton.heading'];
        yield 'search landing.canton.intro' => ['search', 'landing.canton.intro'];
        yield 'venues place.canton' => ['venues', 'place.canton'];
        yield 'venues detail.actions.share_text_canton' => ['venues', 'detail.actions.share_text_canton'];
    }

    #[DataProvider('messages')]
    public function test_liechtenstein_has_no_kanton_and_a_swiss_canton_keeps_it(string $domain, string $key): void
    {
        $liechtenstein = $this->render($domain, $key, Canton::LI);
        self::assertStringContainsString('Liechtenstein', $liechtenstein);
        self::assertStringNotContainsString('Kanton', $liechtenstein);

        $zurich = $this->render($domain, $key, Canton::ZH);
        self::assertStringContainsString('Kanton Zürich', $zurich);
        self::assertStringNotContainsString('{', $zurich, 'every placeholder is filled');
    }

    private function render(string $domain, string $key, Canton $canton): string
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator->trans($key, [
            'canton' => $canton->trans($translator),
            'canton_code' => $canton->value,
            'count' => 5,
            'page' => '2',
            'name' => 'Schloss Au',
            'site' => 'deine-eventlocation.ch',
        ], $domain);
    }
}
