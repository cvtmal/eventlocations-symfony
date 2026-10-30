<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tokens and base components of the back office, dev and test only. #23 adds its components through
 * the slot `admin/styleguide/_components.html.twig`.
 */
#[IsGranted('ROLE_EDITOR')]
final class StyleguideController extends AbstractController
{
    #[Route('/admin/_styleguide', name: 'admin_styleguide', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true], env: ['dev', 'test'])]
    public function __invoke(): Response
    {
        return $this->render('admin/styleguide/index.html.twig');
    }
}
