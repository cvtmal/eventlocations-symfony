<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use Doctrine\ORM\Mapping\Column;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * Doctrine writes every `#[ORM\Column]` property when it hydrates a row, and re-reads
 * generated columns after INSERT and UPDATE. PHPStan cannot see either, so without this
 * a generated column fails "property.onlyRead" and a nullable one "property.unusedType".
 */
final class DoctrineColumnExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMappedColumn($property, $propertyName);
    }

    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMappedColumn($property, $propertyName);
    }

    private function isMappedColumn(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass()->getNativeReflection();

        return $class->hasProperty($propertyName)
            && [] !== $class->getProperty($propertyName)->getAttributes(Column::class);
    }
}
