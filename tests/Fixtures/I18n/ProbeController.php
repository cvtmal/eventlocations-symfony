<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\I18n;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The demo page of the i18n probe kernels: strings from two domains, Swiss formats and hreflang.
 * Exists only in I18nProbeKernel and FrenchProbeKernel, never in the app.
 */
#[AsController]
final class ProbeController extends AbstractController
{
    #[Route(path: ['de' => '/i18n-probe', 'fr' => '/fr/i18n-probe'], name: 'i18n_probe', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@i18n_probe/probe.html.twig');
    }
}
