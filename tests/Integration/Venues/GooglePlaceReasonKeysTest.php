<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Venues\ValueObject\GooglePlaceReason;
use App\Venues\ValueObject\GooglePlaceVerdict;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The place ID report's reason sentences (#57): keys built at runtime from `GooglePlaceReason`, invisible to
 * `debug:translation`.
 */
final class GooglePlaceReasonKeysTest extends KernelTestCase
{
    public function test_every_reason_has_a_sentence_in_the_venues_catalogue(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        foreach (GooglePlaceReason::cases() as $reason) {
            self::assertTrue($catalogue->defines('google_place.reason.'.$reason->value, 'venues+intl-icu'), $reason->value);
        }
    }

    public function test_a_verdict_renders_its_words_area_km_and_numbers(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame('Google kennt diese Wörter unseres Namens nicht: rosenau, wiesental.', GooglePlaceVerdict::doubtful(GooglePlaceReason::NameDiffers, ['rosenau', 'wiesental'])->trans($translator, 'de'));
        self::assertSame('Ort passt nicht: Google nennt Wil, St. Gallen, Schweiz.', GooglePlaceVerdict::doubtful(GooglePlaceReason::PlaceDiffers, area: 'Wil, St. Gallen, Schweiz')->trans($translator, 'de'));
        self::assertSame('1.4 km von unserem Pin entfernt.', GooglePlaceVerdict::doubtful(GooglePlaceReason::FarFromPin, km: 1.4)->trans($translator, 'de'));
        self::assertSame('Derselbe Google-Ort passt auch zu Location 12, 40.', GooglePlaceVerdict::samePlace([12, 40])->trans($translator, 'de'));
        self::assertSame('Name und Ort stimmen überein.', GooglePlaceVerdict::match()->trans($translator, 'de'));
        self::assertSame('Google findet nichts zu dieser Suche.', GooglePlaceVerdict::notFound()->trans($translator, 'de'));
        self::assertSame('Anfrage an Google fehlgeschlagen. places-find nochmals ausführen.', GooglePlaceVerdict::failed()->trans($translator, 'de'));
    }
}
