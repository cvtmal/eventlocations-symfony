<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\RightsPolicyText;
use PHPUnit\Framework\TestCase;

/**
 * The version of a declaration text is content-addressed (G1): nobody bumps a number, an edit of
 * the words is a new version, and the same words are always the same version.
 */
final class RightsPolicyTextTest extends TestCase
{
    public function test_the_version_is_the_first_twelve_hex_characters_of_the_sha256(): void
    {
        $text = RightsPolicyText::of('abc');

        // sha256("abc") = ba7816bf 8f01cfea 414140de 5dae2223 …
        self::assertSame('ba7816bf8f01', $text->version);
        self::assertSame('abc', $text->markdown);
    }

    public function test_the_same_words_are_the_same_version_and_any_edit_is_another(): void
    {
        $base = RightsPolicyText::of("**Bildrechte-Erklärung**\n\nDu darfst die Fotos zeigen.");

        self::assertSame($base->version, RightsPolicyText::of("**Bildrechte-Erklärung**\n\nDu darfst die Fotos zeigen.")->version);
        self::assertNotSame($base->version, RightsPolicyText::of("**Bildrechte-Erklärung**\n\nDu darfst die Fotos zeigen!")->version);
        self::assertNotSame($base->version, RightsPolicyText::of("**Bildrechte-Erklärung**\n\nDu darfst die Fotos zeigen. ")->version);
    }

    public function test_the_version_fits_the_policy_version_column_and_its_check(): void
    {
        foreach (['a', 'Grössen', str_repeat('Text. ', 400)] as $markdown) {
            // chk_rights_policy_versions_version: ^[a-z0-9]{3,40}$
            self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', RightsPolicyText::of($markdown)->version);
        }
    }
}
