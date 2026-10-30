<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\ORM\EntityManagerInterface;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class InfrastructureAccessTest extends ArchitectureRules
{
    public function test_controllers_do_not_use_the_entity_manager(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Controller'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(EntityManagerInterface::class))
            ->because('Writes go through command handlers; reads go through repositories.');
    }

    public function test_console_commands_do_not_use_the_entity_manager(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Console'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(EntityManagerInterface::class))
            ->because('Writes go through command handlers; reads go through repositories.');
    }

    public function test_read_models_do_not_use_entities_or_the_entity_manager(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('ReadModel'))
            ->shouldNot()->dependOn()
            ->classes(self::layer('Entity'), Selector::classname(EntityManagerInterface::class))
            ->because('Public pages read through hand-written SQL into view objects and never hydrate entities (S2).');
    }

    public function test_modules_do_not_depend_on_test_code(): Rule
    {
        return PHPat::rule()
            ->classes(self::modules())
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Tests'),
                Selector::inNamespace('App\DataFixtures'),
                Selector::inNamespace('App\Story'),
                Selector::inNamespace('Zenstruck\Foundry'),
            )
            ->because('Fixtures and factories are dev-only and are not installed in production.');
    }
}
