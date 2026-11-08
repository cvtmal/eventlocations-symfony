<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Seo\HomeMeta;
use App\Seo\MetaDescription;
use App\Seo\TitleChain;
use App\Seo\ValueObject\PageMeta;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HomeMetaTest extends KernelTestCase
{
    public function test_the_title_fits_and_is_not_the_listings(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $title = $this->meta()->title;

        self::assertSame('Eventlokale in der Schweiz finden | deine-eventlocation.ch', $title);
        self::assertLessThanOrEqual(TitleChain::MAX, mb_strlen($title));
        self::assertNotSame($translator->trans('listing.title', ['site' => 'deine-eventlocation.ch'], 'seo'), $title);
    }

    public function test_the_description_fits(): void
    {
        $description = $this->meta()->description;

        self::assertStringStartsWith('Finde das passende Eventlokal', $description);
        self::assertLessThanOrEqual(MetaDescription::MAX, mb_strlen($description));
    }

    public function test_the_page_is_an_indexable_website_with_the_root_as_canonical(): void
    {
        $meta = $this->meta();

        // No request here, so the host is DEFAULT_URI's (LandingPathsTest does the same).
        self::assertMatchesRegularExpression('~^http://localhost(:\d+)?/$~', $meta->canonical);
        self::assertSame('website', $meta->ogType);
        self::assertFalse($meta->noindex);
    }

    private function meta(): PageMeta
    {
        $meta = self::getContainer()->get(HomeMeta::class);
        self::assertInstanceOf(HomeMeta::class, $meta);

        return $meta->for();
    }
}
