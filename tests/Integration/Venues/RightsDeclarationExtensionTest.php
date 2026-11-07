<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Venues\RightsPolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * `{{ rights_declaration() }}` (G1) prints the declaration text in force as HTML that Twig does not escape again.
 */
final class RightsDeclarationExtensionTest extends KernelTestCase
{
    use ResetsContentBlocks;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        parent::tearDown();
    }

    public function test_the_function_prints_the_current_text_as_html(): void
    {
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);

        $html = $this->render('<div>{{ rights_declaration() }}</div>');

        self::assertSame('<div>'.$policy->currentHtml().'</div>', $html);
        self::assertStringContainsString('<strong>Bildrechte-Erklärung</strong>', $html);
        self::assertStringContainsString('<ol>', $html);
        self::assertStringNotContainsString('&lt;', $html, 'safe HTML is printed as it is, not escaped a second time');
    }

    private function render(string $template): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate($template)->render();
    }
}
