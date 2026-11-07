<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A source read, not a rendered mail: no mail reaches every macro, so only this catches a macro that keeps a colour of
 * another brand. A mail cannot read CSS variables, so its colours are literal hex; every one of them must be a colour of
 * `abstracts/_tokens.scss`, white or black, or one of the dark-mode neutrals below, which belong to the mail's dark block
 * and to no brand. After a palette change this test lists the mail literals to move (docs/brand.md).
 */
final class MailLiteralsTest extends TestCase
{
    /** The neutrals of `layout.html.twig`'s `prefers-color-scheme: dark` block, shared by every brand. */
    private const array DARK_MODE_NEUTRALS = ['121212', '272727', 'ececec', 'c5c5c5', '5f5f5f'];

    /** The dark-mode link tint: a light tint of the primary, readable on the dark card (#272727). */
    private const array DARK_MODE_LINK = ['c7caff'];

    public function test_every_colour_of_a_mail_is_a_token_of_the_brand(): void
    {
        $allowed = [...array_values($this->tokens()), 'ffffff', '000000', ...self::DARK_MODE_NEUTRALS, ...self::DARK_MODE_LINK];

        $templates = iterator_to_array($this->mailTemplates(), false);
        self::assertNotSame([], $templates);

        foreach ($templates as $template) {
            preg_match_all('/(?<![&\w])#([0-9a-f]{6})\b/i', $template->getContents(), $matches);
            foreach ($matches[1] as $hex) {
                self::assertContains(strtolower($hex), $allowed, \sprintf('#%s in %s is not a colour of the brand tokens.', $hex, $template->getPathname()));
            }
        }
    }

    public function test_the_mail_carries_the_primary_and_the_ink_of_the_tokens(): void
    {
        $tokens = $this->tokens();
        $blocks = (string) file_get_contents($this->root().'/templates/emails/_blocks.html.twig');
        $layout = (string) file_get_contents($this->root().'/templates/emails/layout.html.twig');

        self::assertStringContainsString('#'.$tokens['accent-strong'], $blocks, 'The button and the quote edge are the primary.');
        self::assertStringContainsString('#'.$tokens['ink'], $blocks, 'The text is the ink.');
        self::assertStringContainsString('#'.$tokens['surface-alt'], $layout, 'The ground is the alternating surface.');
        self::assertStringContainsString('#'.$tokens['accent'], $layout, 'The bar under the logo is the accent.');
    }

    public function test_the_dark_block_stays_out_of_the_blocks(): void
    {
        $blocks = (string) file_get_contents($this->root().'/templates/emails/_blocks.html.twig');

        foreach ([...self::DARK_MODE_NEUTRALS, ...self::DARK_MODE_LINK] as $literal) {
            self::assertStringNotContainsStringIgnoringCase('#'.$literal, $blocks);
        }
    }

    /**
     * @return array<string, string> token name without `--c-` => hex without `#`
     */
    private function tokens(): array
    {
        $scss = (string) file_get_contents($this->root().'/assets/styles/abstracts/_tokens.scss');
        preg_match_all('/--c-([a-z0-9-]+):\s*#([0-9a-f]{6}|[0-9a-f]{3})\s*;/i', $scss, $matches, \PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $hex = strtolower($match[2]);
            $tokens[$match[1]] = 3 === \strlen($hex) ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex;
        }
        self::assertArrayHasKey('accent-strong', $tokens);

        return $tokens;
    }

    private function mailTemplates(): Finder
    {
        return Finder::create()->files()->depth(0)->name('*.twig')->in([
            $this->root().'/templates/emails',
            $this->root().'/templates/inquiries/email',
        ]);
    }

    private function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
