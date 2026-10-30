<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class AdminRoutesAreProtectedTest extends WebTestCase
{
    use AdminSession;
    use ChecksAdminRoutes;
    use Factories;
}
