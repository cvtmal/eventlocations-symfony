<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Messenger\CommandHandlerInterface;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ClassModifiersTest extends ArchitectureRules
{
    public function test_controllers_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Controller'))
            ->should()->beFinal()
            ->because('Controllers are entry points, not extension points.');
    }

    public function test_commands_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Command'))
            ->should()->beFinal()
            ->because('Commands are plain data carriers.');
    }

    public function test_commands_are_readonly(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Command'))
            ->should()->beReadonly()
            ->because('A dispatched command must not change on its way to the handler.');
    }

    public function test_command_handlers_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('CommandHandler'))
            ->excluding(Selector::isInterface())
            ->should()->beFinal()
            ->because('Handlers are composed through the bus, not inherited.');
    }

    public function test_command_handlers_are_invokable(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('CommandHandler'))
            ->excluding(Selector::isInterface())
            ->should()->beInvokable()
            ->because('Messenger calls a handler through __invoke().');
    }

    public function test_exceptions_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Exception'))
            ->excluding(Selector::isAbstract())
            ->should()->beFinal()
            ->because('Each business error is one concrete type with named constructors.');
    }

    public function test_event_listeners_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('EventListener'))
            ->should()->beFinal()
            ->because('Listeners are wired by attribute, not inherited.');
    }

    public function test_twig_extensions_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Twig'))
            ->should()->beFinal()
            ->because('Twig extensions are wired by attribute, not inherited.');
    }

    public function test_console_commands_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Console'))
            ->should()->beFinal()
            ->because('Console commands are entry points, not extension points.');
    }

    public function test_mailers_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Mailer'))
            ->should()->beFinal()
            ->because('Mailers are services, composed not inherited.');
    }

    public function test_events_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Event'))
            ->should()->beFinal()
            ->because('Events and their publishers are composed, not inherited.');
    }

    public function test_events_are_readonly(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Event'))
            ->should()->beReadonly()
            ->because('An event is a fact; subscribers must not change it.');
    }

    public function test_read_models_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('ReadModel'))
            ->excluding(Selector::isInterface(), Selector::isAbstract())
            ->should()->beFinal()
            ->because('Read models are query services and view objects, composed not inherited.');
    }

    public function test_value_objects_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('ValueObject'))
            ->excluding(Selector::isInterface(), Selector::isAbstract(), Selector::isEnum())
            ->should()->beFinal()
            ->because('Value objects are compared by value, never extended.');
    }

    public function test_value_objects_are_readonly(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('ValueObject'))
            ->excluding(Selector::isInterface(), Selector::isAbstract(), Selector::isEnum())
            ->should()->beReadonly()
            ->because('A value object never changes after construction.');
    }

    public function test_entities_are_not_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Entity'))
            ->shouldNot()->beFinal()
            ->because('Doctrine needs to proxy entities for lazy loading.');
    }

    public function test_entities_are_not_readonly(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Entity'))
            ->shouldNot()->beReadonly()
            ->because('Doctrine hydrates entities by writing their properties.');
    }

    public function test_extensions_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Extension'))
            ->excluding(Selector::isInterface(), Selector::isEnum(), Selector::isAbstract())
            ->should()->beFinal()
            ->because('Extension-point implementations are wired by tag, not inherited.');
    }

    public function test_security_classes_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Security'))
            ->excluding(Selector::isInterface())
            ->should()->beFinal()
            ->because('Security classes are services, composed not inherited.');
    }

    public function test_messenger_classes_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Messenger'))
            ->excluding(Selector::isInterface())
            ->should()->beFinal()
            ->because('Middleware and message helpers are composed not inherited.');
    }

    public function test_command_handler_marker_is_an_interface(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(CommandHandlerInterface::class))
            ->should()->beInterface()
            ->because('The marker only exists to be implemented and tagged.');
    }
}
