<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Shared\Funnel\FunnelEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * G8: the funnel screen builds its step labels at runtime (`stats.funnel.step.<FunnelEvent value>`), which
 * `debug:translation` cannot see. A new `FunnelEvent` case without a label fails here, not on the staff screen.
 */
final class FunnelTranslationKeysTest extends KernelTestCase
{
    public function test_every_funnel_step_has_a_label(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        foreach (FunnelEvent::cases() as $event) {
            $key = 'stats.funnel.step.'.$event->value;
            self::assertTrue($catalogue->defines($key, 'cms+intl-icu') || $catalogue->defines($key, 'cms'), $key);
            self::assertNotSame($key, $catalogue->get($key, 'cms+intl-icu'), $key);
        }
    }
}
