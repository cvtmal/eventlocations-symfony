<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Twig and Vue render the same markup and classes (S12). Each side compares itself with a committed fixture in
 * `admin-ui/tests/fixtures/markup/`, so the order of PHPUnit and Vitest in `make test` does not matter.
 * `admin-ui/tests/markup-parity.test.js` is the Vue side and canonicalises the same way.
 */
final class ComponentMarkupFixturesTest extends KernelTestCase
{
    private const array VOID = ['input'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function components(): iterable
    {
        yield 'button' => ['button', <<<'TWIG'
            {{ include('admin/components/_button.html.twig', {label: 'Speichern', variant: 'primary', icon: 'plus'}, with_context = false) }}
            TWIG];

        yield 'pill' => ['pill', <<<'TWIG'
            {{ include('admin/components/_pill.html.twig', {label: 'Publiziert', tone: 'green', dot: true}, with_context = false) }}
            TWIG];

        yield 'table row' => ['table-row', <<<'TWIG'
            {% embed 'admin/components/_table_row.html.twig' with {select_name: 'venue[]', select_value: '1042', select_label: 'Zeile 1042 auswählen'} only %}
                {% block cells %}<td>Schloss Seeblick</td>{% endblock %}
                {% block actions %}{{ include('admin/components/_button.html.twig', {label: 'Aktionen', variant: 'ghost', icon: 'ellipsis', icon_only: true}, with_context = false) }}{% endblock %}
            {% endembed %}
            TWIG];

        yield 'table row without actions' => ['table-row-no-actions', <<<'TWIG'
            {% embed 'admin/components/_table_row.html.twig' with {actions_column: true} only %}
                {% block cells %}<td>Schloss Seeblick</td>{% endblock %}
            {% endembed %}
            TWIG];

        yield 'modal' => ['modal', <<<'TWIG'
            {% embed 'admin/components/_modal.html.twig' with {id: 'demo', title: 'Änderungen verwerfen?', close_label: 'Schliessen'} only %}
                {% block body %}<p>Nicht gespeicherte Eingaben gehen verloren.</p>{% endblock %}
                {% block footer %}{{ include('admin/components/_button.html.twig', {label: 'Abbrechen'}, with_context = false) }}{% endblock %}
            {% endembed %}
            TWIG];
    }

    #[DataProvider('components')]
    public function test_twig_renders_the_fixture(string $fixture, string $template): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate($template)->render();
        $path = \dirname(__DIR__, 3).'/admin-ui/tests/fixtures/markup/'.$fixture.'.html';

        self::assertSame(trim((string) file_get_contents($path)), self::canonical(self::element($html)));
    }

    /**
     * A `<tr>` outside a table does not survive parsing.
     */
    private static function element(string $html): \Dom\Element
    {
        $isRow = str_starts_with(trim($html), '<tr');
        $document = \Dom\HTMLDocument::createFromString(
            '<!doctype html><html><body>'.($isRow ? '<table><tbody>'.$html.'</tbody></table>' : $html).'</body></html>',
            \LIBXML_NOERROR,
        );

        $element = $document->querySelector($isRow ? 'tr' : 'body > *');
        self::assertNotNull($element);

        return $element;
    }

    private static function canonical(\Dom\Element $element): string
    {
        $tag = $element->localName;

        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $value = $attribute->value;
            $attributes[$attribute->name] = 'use' === $tag && 'href' === $attribute->name ? (string) strstr($value, '#') : $value;
        }
        ksort($attributes);

        $markup = '<'.$tag;
        foreach ($attributes as $name => $value) {
            $markup .= \sprintf(' %s="%s"', $name, htmlspecialchars($value, \ENT_COMPAT | \ENT_SUBSTITUTE, 'UTF-8'));
        }
        $markup .= '>';

        if (\in_array($tag, self::VOID, true)) {
            return $markup;
        }

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element) {
                $markup .= self::canonical($node);
            } elseif ($node instanceof \Dom\Text) {
                $markup .= htmlspecialchars(trim((string) $node->textContent), \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $markup.'</'.$tag.'>';
    }
}
