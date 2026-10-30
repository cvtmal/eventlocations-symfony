<?php

declare(strict_types=1);

namespace App\Favorites\ValueObject;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Merkliste cookie (S7): public venue numbers in saved order, at most 100. A cookie value is
 * visitor input, so parsing drops anything that is not a number Postgres can hold; a value above
 * the INTEGER range would otherwise fail the query on /merkliste. assets/js/merkliste.js applies
 * the same rule and writes the same format, plus a trailing comma: the marker for a list written
 * by JS and not yet re-issued by the server. `parse()` drops it and `value()` never writes it.
 *
 * Only `POST /merkliste/{number}`, `POST /merkliste` and `GET /merkliste` read it. Public pages
 * never do, or they could not be cached (S10).
 */
final readonly class MerklisteCookie
{
    public const NAME = 'merkliste';
    public const MAX = 100;
    public const MAX_AGE_SECONDS = 34560000;

    private const MAX_NUMBER = 2147483647;

    /**
     * @param list<int> $numbers
     */
    private function __construct(private array $numbers)
    {
    }

    /**
     * PHP parses a cookie named `merkliste[a]` into an array, and `InputBag::get()` would then
     * throw, so this reads the raw bag and treats anything but a string as absent.
     */
    public static function fromRequest(Request $request): self
    {
        $value = $request->cookies->all()[self::NAME] ?? null;

        return self::parse(\is_string($value) ? $value : null);
    }

    public static function parse(?string $value): self
    {
        $numbers = [];
        foreach (explode(',', (string) $value) as $token) {
            if (\count($numbers) >= self::MAX) {
                break;
            }
            if (1 !== preg_match('/^[1-9]\d{0,9}$/D', $token)) {
                continue;
            }
            $number = (int) $token;
            if (self::isValidNumber($number) && !\in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return new self($numbers);
    }

    public static function isValidNumber(int $number): bool
    {
        return $number >= 1 && $number <= self::MAX_NUMBER;
    }

    /**
     * @return list<int>
     */
    public function numbers(): array
    {
        return $this->numbers;
    }

    public function has(int $number): bool
    {
        return \in_array($number, $this->numbers, true);
    }

    public function isFull(): bool
    {
        return \count($this->numbers) >= self::MAX;
    }

    /**
     * Saved entries go, new ones join the end. A full list refuses a new entry: nothing is
     * evicted, because the oldest entry is the one a couple saved first.
     */
    public function toggled(int $number): self
    {
        if ($this->has($number)) {
            return $this->without($number);
        }

        return $this->isFull() ? $this : new self([...$this->numbers, $number]);
    }

    /**
     * Only ever removes, so the same removal sent twice cannot bring an entry back.
     */
    public function without(int $number): self
    {
        return new self(array_values(array_diff($this->numbers, [$number])));
    }

    /**
     * @param list<int> $numbers
     */
    public function only(array $numbers): self
    {
        return new self(array_values(array_intersect($this->numbers, $numbers)));
    }

    public function value(): string
    {
        return implode(',', $this->numbers);
    }

    /**
     * `secure: null` lets `Response::prepare()` follow the request, so the cookie is `Secure` on
     * HTTPS. An empty value renders as Symfony's deletion, so there is one code path.
     */
    public function toCookie(): Cookie
    {
        return Cookie::create(self::NAME, $this->value(), time() + self::MAX_AGE_SECONDS, '/', null, null, false, false, Cookie::SAMESITE_LAX);
    }
}
