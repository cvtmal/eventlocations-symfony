<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_EDITOR')]
final class FakeRecordController extends AbstractController
{
    #[Route('/admin/_probe/datensatz/{id}', name: 'admin_probe_record', methods: ['GET'], options: ['unlocalized' => true])]
    public function __invoke(string $id): Response
    {
        return $this->render('@admin_probe/record.html.twig', ['id' => $id]);
    }
}
