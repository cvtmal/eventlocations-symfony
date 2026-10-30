<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Inquiries\ValueObject\Occasion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keys built at runtime are invisible to debug:translation (.claude/rules/i18n.md).
 */
final class OccasionLabelsTest extends KernelTestCase
{
    public function test_every_occasion_has_a_german_label(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        foreach (Occasion::cases() as $occasion) {
            self::assertNotSame('occasion.'.$occasion->value, $occasion->trans($translator, 'de'));
        }
        self::assertSame('Hochzeit', Occasion::Wedding->trans($translator, 'de'));
    }
}
