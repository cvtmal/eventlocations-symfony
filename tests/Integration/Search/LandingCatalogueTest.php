<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Search\ReadModel\LandingPage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every building term can become a landing page (#17), so each one needs a slug, a heading and
 * an intro in the `search` catalogue. The codes come from `taxonomy_terms`, so a migration that
 * adds a building term fails here until its copy exists.
 */
final class LandingCatalogueTest extends KernelTestCase
{
    /** `{heading} | {site}` must fit SEO-1's 60 characters. */
    private const int MAX_HEADING = 36;

    private TranslatorInterface $translator;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        $translator = self::getContainer()->get('translator');
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(Connection::class, $connection);
        $this->translator = $translator;
        /** @var list<string> $codes */
        $codes = $connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'building' ORDER BY code");
        $this->codes = $codes;
        self::assertNotEmpty($this->codes);
    }

    public function test_every_building_term_has_its_copy(): void
    {
        foreach ($this->codes as $code) {
            foreach (['slug', 'heading', 'intro'] as $part) {
                $key = 'landing.building.'.$code.'.'.$part;
                self::assertNotSame($key, $this->translator->trans($key, ['count' => 5], 'search'), \sprintf('Missing %s in the search catalogue.', $key));
            }
        }
    }

    public function test_building_slugs_fit_the_route_and_are_unique(): void
    {
        $slugs = $this->buildingSlugs();

        foreach ($slugs as $code => $slug) {
            self::assertMatchesRegularExpression('~^'.LandingPage::SLUG_REQUIREMENT.'$~', $slug, $code);
        }
        self::assertSame(array_values(array_unique($slugs)), array_values($slugs), 'Two building types share a slug.');
    }

    public function test_building_and_canton_slugs_are_disjoint(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $cantonSlugs = $connection->fetchFirstColumn('SELECT slug FROM cantons');

        self::assertCount(27, $cantonSlugs);
        self::assertSame([], array_values(array_intersect($this->buildingSlugs(), $cantonSlugs)), '/hochzeitslocations/{slug} resolves a canton first, so a building would never be reached.');
    }

    public function test_headings_fit_a_title_and_intros_render(): void
    {
        foreach ($this->codes as $code) {
            $heading = $this->translator->trans('landing.building.'.$code.'.heading', [], 'search');
            self::assertLessThanOrEqual(self::MAX_HEADING, mb_strlen($heading), $heading);

            foreach ([5, 100] as $count) {
                $intro = $this->translator->trans('landing.building.'.$code.'.intro', ['count' => $count], 'search');
                self::assertStringStartsWith($count.' ', $intro);
                self::assertLessThanOrEqual(200, mb_strlen($intro), $intro);
            }
        }
    }

    /**
     * @return array<string, string> code → slug
     */
    private function buildingSlugs(): array
    {
        $slugs = [];
        foreach ($this->codes as $code) {
            $slugs[$code] = $this->translator->trans('landing.building.'.$code.'.slug', [], 'search');
        }

        return $slugs;
    }
}
