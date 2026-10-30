<?php

declare(strict_types=1);

namespace App\Shared\Mailer;

use Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface;

/**
 * Derives a mail's text part from its HTML. The mails are nested presentation tables, so strip_tags
 * would keep the hidden preheader and lose every button URL.
 */
final class EmailHtmlToTextConverter implements HtmlToTextConverterInterface
{
    public function convert(string $html, string $charset): string
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, $charset);

        foreach ($document->querySelectorAll('head, style, script, .hl-preheader, [aria-hidden="true"]') as $node) {
            $node->remove();
        }
        foreach ($document->querySelectorAll('a.hl-btn') as $button) {
            $label = trim($button->textContent ?? '');
            $button->replaceWith($document->createTextNode($label.': '.$button->getAttribute('href')));
        }
        foreach ($document->querySelectorAll('br') as $break) {
            $break->replaceWith($document->createTextNode("\n"));
        }

        // A row is a cell that holds no other cell; layout cells only wrap rows.
        $blocks = [];
        foreach ($document->querySelectorAll('td') as $cell) {
            if (null !== $cell->querySelector('td')) {
                continue;
            }
            $text = self::tidy($cell->textContent ?? '');
            if ('' !== $text) {
                $blocks[] = $text;
            }
        }

        if ([] === $blocks) {
            return self::tidy($document->body->textContent ?? '');
        }

        return implode("\n\n", $blocks);
    }

    private static function tidy(string $text): string
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
