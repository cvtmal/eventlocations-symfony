<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A source read, not a rendered mail: no mail reaches every macro, so only this catches a macro that lands with the
 * old look. `#121212` and `#5f5f5f` stay in the dark block of `layout.html.twig`, so they are barred from `_blocks` alone.
 */
final class MailLiteralsTest extends TestCase
{
    private const array OLD_LITERALS = ['#ff5a75', '#ffc1cb', '#2c2c2c', '#f1f1f1', '#dedede'];

    private const array OLD_LIGHT_LITERALS = ['#121212', '#5f5f5f'];

    public function test_no_mail_template_holds_an_old_literal(): void
    {
        $templates = iterator_to_array($this->mailTemplates());
        self::assertNotSame([], $templates);

        foreach ($templates as $template) {
            foreach (self::OLD_LITERALS as $literal) {
                self::assertStringNotContainsStringIgnoringCase($literal, $template->getContents(), $template->getPathname());
            }
        }
    }

    public function test_the_blocks_hold_no_literal_of_the_old_light_look(): void
    {
        $blocks = (string) file_get_contents($this->root().'/templates/emails/_blocks.html.twig');
        self::assertNotSame('', $blocks);

        foreach (self::OLD_LIGHT_LITERALS as $literal) {
            self::assertStringNotContainsStringIgnoringCase($literal, $blocks);
        }
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
