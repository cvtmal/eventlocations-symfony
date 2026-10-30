<?php

declare(strict_types=1);

namespace App\Tests\Support\Twig;

use Symfony\Bridge\Twig\Node\TransNode;
use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\BlockReferenceNode;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\Node\TextNode;
use Twig\Source;
use Twig\Token;

/**
 * Finds user-facing text in a Twig template that bypasses the translator: raw text between
 * tags, letters in alt/title/aria-label/placeholder attributes, and string literals printed
 * without |trans ({{ 'Text' }}). Every non-text node becomes a newline before tags are
 * stripped, so a tag split by {{ }} never yields a false finding; block bodies are kept
 * apart the same way. Script and style bodies and {% trans %} blocks are ignored.
 *
 * Limits: text passed as a variable to includes, macros or components, and PHP literals.
 */
final readonly class RawTextScanner
{
    private const LETTER = '/\p{L}/u';

    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * @return list<string>
     */
    public function scan(string $code, string $name): array
    {
        $source = new Source($code, $name);
        $findings = [];

        // Twig's optimizer turns {{ 'Text' }} into a TextNode while parsing, so string
        // literals printed on their own are found in the token stream.
        $constantPrints = [];
        $stream = $this->twig->tokenize($source);
        while (!$stream->isEOF()) {
            $token = $stream->next();
            if (!$token->test(Token::VAR_START_TYPE)) {
                continue;
            }
            $value = $stream->getCurrent();
            if ($value->test(Token::STRING_TYPE) && $stream->look()->test(Token::VAR_END_TYPE)) {
                $literal = (string) $value->getValue();
                if (1 === preg_match(self::LETTER, $literal)) {
                    $findings[] = \sprintf("{{ '%s' }}", $literal);
                }
                $constantPrints[] = [$value->getLine(), $literal];
            }
        }

        $html = $this->flatten($this->twig->parse($this->twig->tokenize($source)), $findings, $constantPrints);

        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);

        preg_match_all('/\b(alt|title|aria-label|placeholder)\s*=\s*"([^"]*)"/iu', $html, $attributes, \PREG_SET_ORDER);
        foreach ($attributes as [, $attribute, $value]) {
            $value = trim($value);
            if (1 === preg_match(self::LETTER, $value)) {
                $findings[] = \sprintf('%s="%s"', $attribute, $value);
            }
        }

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5);
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if (1 === preg_match(self::LETTER, $line)) {
                $findings[] = $line;
            }
        }

        $findings = array_values(array_unique($findings));
        sort($findings);

        return $findings;
    }

    /**
     * @param list<string>                   $findings
     * @param array<int, array{int, string}> $constantPrints
     */
    private function flatten(Node $node, array &$findings, array &$constantPrints): string
    {
        if ($node instanceof TextNode) {
            $data = (string) $node->getAttribute('data');
            $index = array_search([$node->getTemplateLine(), $data], $constantPrints, true);
            if (false !== $index) {
                // A printed literal the optimizer inlined; already recorded from the tokens.
                unset($constantPrints[$index]);

                return "\n";
            }

            return $data;
        }

        if ($node instanceof TransNode || $node instanceof BlockReferenceNode) {
            return "\n";
        }

        if ($node instanceof PrintNode) {
            $expression = $node->getNode('expr');
            if ($expression instanceof ConstantExpression) {
                $value = $expression->getAttribute('value');
                if (\is_string($value) && 1 === preg_match(self::LETTER, $value)) {
                    $findings[] = \sprintf("{{ '%s' }}", $value);
                }
            }

            return "\n";
        }

        $html = '';
        foreach ($node as $child) {
            $html .= $this->flatten($child, $findings, $constantPrints);
        }

        // Block bodies are stored apart from where they are rendered; keep them apart.
        return $node instanceof BlockNode ? "\n".$html."\n" : $html;
    }
}
