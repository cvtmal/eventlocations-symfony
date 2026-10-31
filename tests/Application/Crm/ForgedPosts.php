<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

/**
 * The POSTs no CRM form may accept. Each one says it comes from this site unless the origin is the point, so
 * the token is what gets refused. `csrf-token` is the stateless forms' constant value (`config/packages/csrf.yaml`).
 */
trait ForgedPosts
{
    /**
     * @return iterable<string, array{array<string, string>, array<string, string>}>
     */
    public static function forgedPosts(): iterable
    {
        yield 'a wrong token' => [['_token' => 'wrong'], ['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'no token' => [[], ['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'another site' => [['_token' => 'csrf-token'], ['HTTP_SEC_FETCH_SITE' => 'cross-site']];
    }
}
