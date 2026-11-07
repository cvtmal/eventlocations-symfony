<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Venues\ValueObject\ModerationKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The public labels of a complaint's kind (G2): keys built at runtime from `ModerationKind`, invisible to
 * `debug:translation`. The staff labels are pinned by `CmsTranslationKeysTest`.
 */
final class ModerationKindKeysTest extends KernelTestCase
{
    public function test_every_kind_has_a_label_in_the_venues_catalogue(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        foreach (ModerationKind::cases() as $kind) {
            self::assertTrue($catalogue->defines('report.kind.'.$kind->value, 'venues+intl-icu'), $kind->value);
        }
    }

    public function test_a_kind_translates_itself(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame('Bildrechte', ModerationKind::Rights->trans($translator, 'de'));
        self::assertSame('Angaben korrigieren', ModerationKind::Correction->trans($translator, 'de'));
    }
}
