<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\ReadModel\ContactExportQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One contact's data as a JSON file (CRM-11, D17, admins only), for an access request. The file name
 * carries the id, not the person's name.
 */
#[IsGranted('ROLE_ADMIN')]
final class ContactDataExportController extends AbstractController
{
    #[Route('/admin/crm/kontakte/{id}/export', name: 'admin_crm_contacts_data_export', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, ContactExportQuery $exports): Response
    {
        $export = $exports->find($id) ?? throw $this->createNotFoundException();

        $response = new JsonResponse(
            json_encode($export->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            json: true,
        );
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'kontakt-'.$id.'.json'));

        return $response;
    }
}
