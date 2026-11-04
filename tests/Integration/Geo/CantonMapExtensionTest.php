<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\ValueObject\Canton;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * {{ canton_map() }} renders the canton SVG unescaped, with every Swiss canton id.
 */
final class CantonMapExtensionTest extends KernelTestCase
{
    public function test_canton_map_renders_every_canton(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate('{{ canton_map() }}')->render();

        self::assertStringStartsWith('<svg', $html);
        foreach (Canton::cases() as $canton) {
            if (Canton::LI === $canton) {
                continue; // no LI shape (#46, owner answer B)
            }
            self::assertStringContainsString('id="ch-'.$canton->value.'"', $html);
        }
        self::assertStringNotContainsString('id="ch-li"', $html);
        self::assertStringContainsString('id="lakes"', $html);
    }
}
