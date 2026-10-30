<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Exception\DomainException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class InheritanceContractsTest extends ArchitectureRules
{
    public function test_controllers_extend_abstract_controller(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Controller'))
            ->excluding(Selector::isAbstract())
            ->should()->extend()
            ->classes(Selector::classname(AbstractController::class))
            ->because('Controllers share the framework helpers (render, forms, redirects).');
    }

    public function test_repositories_extend_service_entity_repository(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Repository'))
            ->should()->extend()
            ->classes(Selector::classname(ServiceEntityRepository::class))
            ->because('Repositories are autowired Doctrine services.');
    }

    public function test_exceptions_extend_domain_exception(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Exception'))
            ->excluding(Selector::isAbstract(), Selector::isInterface())
            ->should()->extend()
            ->classes(Selector::classname(DomainException::class))
            ->because('Business errors are caught and translated as one family.');
    }
}
