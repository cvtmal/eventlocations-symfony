<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * The rules of one host's robots.txt for our crawler, read as RFC 9309 describes: the group that names one of our
 * product tokens wins over `*`, the longest matching pattern wins, and Allow wins a tie. `*` and a trailing `$` are
 * the only wildcards. Paths and patterns are compared in one spelling (RFC 9309 section 2.2.2: non-ASCII bytes
 * percent-encoded, escapes of unreserved characters decoded, hex in upper case). A file that could not be read, or
 * that holds a rule this class will not evaluate (over 300 bytes, or more than 1,000 rules for us), is
 * `unreadable()`: it allows nothing, and says why, because a Disallow we skip would be a page we should not read.
 */
final class RobotsTxt
{
    /** The names a site owner may use in a `User-agent:` line for us (lower case). */
    public const array PRODUCT_TOKENS = ['deine-eventlocation', 'deine-eventlocation.ch', 'site-import'];

    private const int MAX_RULES = 1000;
    private const int MAX_PATTERN_LENGTH = 300;

    /**
     * @param list<array{bool, string}> $rules [allow, pattern]
     */
    private function __construct(
        private readonly array $rules,
        private readonly ?float $crawlDelay,
        private readonly ?string $unreadableBecause,
    ) {
    }

    public static function allowAll(): self
    {
        return new self([], null, null);
    }

    public static function unreadable(string $because): self
    {
        return new self([], null, $because);
    }

    public static function parse(string $body): self
    {
        /** @var list<array{agents: list<string>, rules: list<array{bool, string}>, delay: ?float}> $groups */
        $groups = [];
        /** @var list<string> $agents */
        $agents = [];
        /** @var list<array{bool, string}> $rules */
        $rules = [];
        $delay = null;
        $open = false;
        $inAgents = false;

        foreach (preg_split('/\r\n|\r|\n/', ltrim($body, "\xEF\xBB\xBF")) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ('user-agent' === $field) {
                if (!$inAgents) {
                    if ($open) {
                        $groups[] = ['agents' => $agents, 'rules' => $rules, 'delay' => $delay];
                    }
                    $agents = [];
                    $rules = [];
                    $delay = null;
                    $open = false;
                }
                $agents[] = strtolower($value);
                $inAgents = true;
                continue;
            }
            if (!\in_array($field, ['allow', 'disallow', 'crawl-delay'], true)) {
                continue;
            }

            $open = true;
            $inAgents = false;
            if ('crawl-delay' === $field) {
                $delay = is_numeric($value) ? max(0.0, (float) $value) : null;
            } elseif ('' !== $value && \count($rules) <= self::MAX_RULES) {
                $rules[] = ['allow' === $field, self::normalise($value)];
            }
        }
        if ($open) {
            $groups[] = ['agents' => $agents, 'rules' => $rules, 'delay' => $delay];
        }

        $specific = array_filter($groups, static fn (array $group): bool => [] !== array_intersect($group['agents'], self::PRODUCT_TOKENS));
        $chosen = [] !== $specific ? $specific : array_filter($groups, static fn (array $group): bool => \in_array('*', $group['agents'], true));

        $chosenRules = [];
        $chosenDelay = null;
        foreach ($chosen as $group) {
            $chosenRules = [...$chosenRules, ...$group['rules']];
            $chosenDelay = $group['delay'] ?? $chosenDelay;
        }

        if (\count($chosenRules) > self::MAX_RULES) {
            return self::unreadable('robots.txt has more than '.self::MAX_RULES.' rules for us');
        }
        foreach ($chosenRules as [, $pattern]) {
            if (\strlen($pattern) > self::MAX_PATTERN_LENGTH) {
                return self::unreadable('robots.txt has a rule longer than '.self::MAX_PATTERN_LENGTH.' bytes');
            }
        }

        return new self($chosenRules, $chosenDelay, null);
    }

    /**
     * Whether our crawler may fetch this path (with its query string).
     */
    public function allows(string $pathAndQuery): bool
    {
        if (null !== $this->unreadableBecause) {
            return false;
        }

        $path = self::normalise($pathAndQuery);
        $matchedLength = -1;
        $allowed = true;
        foreach ($this->rules as [$allow, $pattern]) {
            if (!self::matches($pattern, $path)) {
                continue;
            }
            $length = \strlen($pattern);
            if ($length > $matchedLength || ($length === $matchedLength && $allow)) {
                $matchedLength = $length;
                $allowed = $allow;
            }
        }

        return $allowed;
    }

    public function crawlDelay(): ?float
    {
        return $this->crawlDelay;
    }

    /** Why the file could not be read, or null when it was. */
    public function unreadableBecause(): ?string
    {
        return $this->unreadableBecause;
    }

    /**
     * The spelling both sides are compared in: bytes outside printable ASCII percent-encoded, escapes of unreserved
     * characters decoded, the hex digits of every other escape in upper case.
     */
    private static function normalise(string $path): string
    {
        $path = (string) preg_replace_callback('/[^\x21-\x7E]/', static fn (array $match): string => \sprintf('%%%02X', \ord($match[0])), $path);

        return (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $match): string {
            $character = (string) hex2bin($match[1]);

            return 1 === preg_match('/^[A-Za-z0-9\-._~]$/', $character) ? $character : '%'.strtoupper($match[1]);
        }, $path);
    }

    /**
     * A rule matches the start of the path; `*` stands for any run of characters and a final `$` ends the path. Done
     * without a regular expression, so no pattern can make it backtrack catastrophically.
     */
    private static function matches(string $pattern, string $path): bool
    {
        $pattern = str_ends_with($pattern, '$') ? substr($pattern, 0, -1) : $pattern.'*';

        $p = 0;
        $t = 0;
        $star = -1;
        $mark = 0;
        $patternLength = \strlen($pattern);
        $pathLength = \strlen($path);
        while ($t < $pathLength) {
            if ($p < $patternLength && '*' === $pattern[$p]) {
                $star = $p++;
                $mark = $t;
            } elseif ($p < $patternLength && $pattern[$p] === $path[$t]) {
                ++$p;
                ++$t;
            } elseif (-1 !== $star) {
                $p = $star + 1;
                $t = ++$mark;
            } else {
                return false;
            }
        }
        while ($p < $patternLength && '*' === $pattern[$p]) {
            ++$p;
        }

        return $p === $patternLength;
    }
}
