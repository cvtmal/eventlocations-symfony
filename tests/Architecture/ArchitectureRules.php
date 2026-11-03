<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\ClassNamespace;
use PHPat\Selector\Selector;

/**
 * Selectors shared by the phpat rule sets.
 *
 * Code lives in App\<Module>\<Layer>. phpat matches regex selectors against the
 * namespace only (without the short class name), so a layer selector has to
 * match "App\<Module>\<Layer>" and anything nested below it.
 */
abstract class ArchitectureRules
{
    protected const MODULES = 'Shared|Geo|Media|Venues|Search|Favorites|Inquiries|Seo|Stats|Admin|Cms|Crm|Portal';

    /**
     * Every class in the given layer of any module, e.g. layer('Controller').
     */
    protected static function layer(string $layer): ClassNamespace
    {
        return Selector::inNamespace('/^App\\\\('.self::MODULES.')\\\\'.$layer.'(\\\\|$)/', true);
    }

    /**
     * Every class inside any module (excludes App\Kernel, fixtures, stories).
     */
    protected static function modules(): ClassNamespace
    {
        return Selector::inNamespace('/^App\\\\('.self::MODULES.')(\\\\|$)/', true);
    }
}
