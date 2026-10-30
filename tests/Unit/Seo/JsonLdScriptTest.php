<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Seo\JsonLdScript;
use PHPUnit\Framework\TestCase;

final class JsonLdScriptTest extends TestCase
{
    public function test_a_closing_tag_in_a_value_stays_inside_the_block(): void
    {
        $html = JsonLdScript::render(['name' => '</script><script>alert(1)</script>']);

        self::assertSame(1, substr_count($html, '<script'));
        self::assertSame(1, substr_count($html, '</script>'));
    }

    public function test_the_value_survives_decoding(): void
    {
        $html = JsonLdScript::render(['name' => 'Bär & Söhne <AG>', 'url' => 'https://example.ch/a/b']);

        $json = substr($html, \strlen('<script type="application/ld+json">'), -\strlen('</script>'));

        self::assertStringContainsString('Bär', $json);
        self::assertStringContainsString('https://example.ch/a/b', $json);
        self::assertSame(['name' => 'Bär & Söhne <AG>', 'url' => 'https://example.ch/a/b'], json_decode($json, true));
    }
}
