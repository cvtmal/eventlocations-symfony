<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:brand:og-card` and `templates/brand/og_card.svg.twig` (docs/brand.md, "Share cards"): the SVG that `make brand-png`
 * turns into `og-default.png`, and that F7b and F8 fill for venues and landing pages.
 */
final class RenderOgCardCommandTest extends KernelTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/og-card-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function test_the_default_card_carries_the_footer_tagline_and_the_dark_lockup(): void
    {
        $svg = $this->render(['--out' => $this->dir.'/default.svg']);

        self::assertStringContainsString('viewBox="0 0 1200 630"', $svg);
        self::assertStringContainsString('<text', $svg);
        self::assertStringContainsString('Eventlocations in der', $svg);
        self::assertStringContainsString('aria-label="deine-eventlocation.ch"', $svg, 'The lockup is public/brand/logo-dark.svg.');
    }

    public function test_a_venue_card_escapes_its_text_and_is_well_formed_xml(): void
    {
        $svg = $this->render([
            '--out' => $this->dir.'/venue.svg', '--variant' => 'venue', '--title' => 'Bar & Bühne <Zürich>',
            '--eyebrow' => 'Zürich ZH', '--facts' => '20–120 Personen · Seminar',
        ]);

        self::assertStringContainsString('Bar &amp; Bühne &lt;Zürich&gt;', $svg);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg), 'The card is well-formed XML.');
        self::assertSame('1200', $document->documentElement?->getAttribute('width'));
    }

    public function test_the_colours_of_the_card_are_tokens(): void
    {
        $svg = $this->render(['--out' => $this->dir.'/landing.svg', '--variant' => 'landing', '--title' => 'Seminarräume im Kanton Zürich', '--eyebrow' => 'Eventlokale', '--facts' => '48 Locations']);

        $tokens = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/abstracts/_tokens.scss');
        preg_match_all('/#([0-9a-f]{6})\b/i', $svg, $matches);
        self::assertNotSame([], $matches[1]);
        foreach (array_unique(array_map('strtolower', $matches[1])) as $hex) {
            self::assertTrue('ffffff' === $hex || str_contains(strtolower($tokens), '#'.$hex), "#{$hex} is not a colour of the brand tokens.");
        }
    }

    public function test_a_venue_card_without_a_title_is_refused(): void
    {
        $tester = $this->tester();
        $tester->execute(['--out' => $this->dir.'/x.svg', '--variant' => 'venue']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->dir.'/x.svg');
    }

    /**
     * @param array<string, string> $input
     */
    private function render(array $input): string
    {
        $tester = $this->tester();
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return (string) file_get_contents($input['--out']);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:brand:og-card'));
    }
}
