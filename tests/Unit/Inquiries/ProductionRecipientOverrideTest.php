<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * IQ-4: production never redirects venue mail or CRM mail. The committed files production loads (`.env`,
 * `.env.prod` if it exists) leave INQUIRY_RECIPIENT_OVERRIDE empty. `.env.local` on the host is
 * #20's to keep clean.
 */
final class ProductionRecipientOverrideTest extends TestCase
{
    private const VARIABLE = 'INQUIRY_RECIPIENT_OVERRIDE';

    public function test_the_default_env_file_sets_it_empty(): void
    {
        $values = (new Dotenv())->parse((string) file_get_contents(self::projectFile('.env')));

        self::assertArrayHasKey(self::VARIABLE, $values);
        self::assertSame('', $values[self::VARIABLE]);
    }

    public function test_a_prod_env_file_does_not_set_it(): void
    {
        $file = self::projectFile('.env.prod');
        $values = is_file($file) ? (new Dotenv())->parse((string) file_get_contents($file)) : [];

        self::assertSame('', $values[self::VARIABLE] ?? '');
    }

    private static function projectFile(string $name): string
    {
        return \dirname(__DIR__, 3).'/'.$name;
    }
}
