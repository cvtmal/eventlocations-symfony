<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * PostgreSQL returns TIMESTAMPTZ values with microseconds when they are present,
 * which the stock DBAL type cannot parse. This type accepts both shapes.
 */
final class FlexibleDateTimeTzImmutableType extends DateTimeTzImmutableType
{
    private const FORMATS = ['Y-m-d H:i:sO', 'Y-m-d H:i:s.uO'];

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        foreach (self::FORMATS as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, (string) $value);

            if (false !== $dateTime) {
                return $dateTime;
            }
        }

        throw InvalidFormat::new((string) $value, static::class, 'Y-m-d H:i:s[.u]O');
    }
}
