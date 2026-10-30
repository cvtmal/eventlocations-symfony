<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder for `/` until #7 replaces it with the redirect to /hochzeitslocations (S5).
 */
final class HomeController extends AbstractController
{
    #[Route(path: ['de' => '/'], name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('site/placeholder.html.twig');
    }
}
