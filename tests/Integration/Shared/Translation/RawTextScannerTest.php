<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Translation;

use App\Tests\Support\Twig\RawTextScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Self-test of the scanner behind TemplatesHaveNoRawTextTest: it flags what it must and
 * nothing else.
 */
final class RawTextScannerTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function templates(): iterable
    {
        yield 'raw text' => ['<p>Hallo Welt</p>', ['Hallo Welt']];
        yield 'raw alt attribute' => ['<img alt="Foto" src="{{ src }}">', ['alt="Foto"']];
        yield 'printed literal' => ["{{ 'Text' }}", ["{{ 'Text' }}"]];
        yield 'adjacent blocks stay apart' => ['{% block a %}Eins{% endblock %}{% block b %}<p>Zwei</p>{% endblock %}', ['Eins', 'Zwei']];
        yield 'trans tag' => ['{% trans %}Hallo{% endtrans %}', []];
        yield 'trans filter' => ["{{ 'k.x'|trans }}", []];
        yield 'script body' => ['<script>let a = 1;</script>', []];
        yield 'tag split by prints' => ['<link rel="alternate" hreflang="{{ a }}" href="{{ b }}">', []];
        yield 'printed alt attribute' => ['<img alt="{{ x }}">', []];
        yield 'entities' => ['&nbsp;&times;', []];
        yield 'printed number' => ['{{ 50 }}', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('templates')]
    public function test_scanner_flags_only_untranslated_text(string $code, array $expected): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        self::assertSame($expected, (new RawTextScanner($twig))->scan($code, 'inline.html.twig'));
    }
}
