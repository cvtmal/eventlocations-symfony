<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\AuditLogFilterData;
use App\Admin\Form\AuditLogFilterType;
use App\Admin\ReadModel\AdminUserListQuery;
use App\Admin\ReadModel\AuditLogFilter;
use App\Admin\ReadModel\AuditLogQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AuditLogController extends AbstractController
{
    #[Route('/admin/audit-log', name: 'admin_audit_log', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, AuditLogQuery $auditLog, AdminUserListQuery $users, FormFactoryInterface $forms): Response
    {
        $actors = [];
        foreach ($users->all() as $user) {
            $actors[$user->displayName] = $user->id;
        }

        $data = new AuditLogFilterData();
        // An empty name keeps the parameters flat: ?actor=…&from=…
        $form = $forms->createNamed('', AuditLogFilterType::class, $data, ['actors' => $actors]);
        $form->handleRequest($request);

        $filter = $form->isSubmitted() && $form->isValid() ? $data->toFilter() : new AuditLogFilter();

        return $this->render('admin/audit/index.html.twig', [
            'form' => $form,
            'log' => $auditLog->search($filter, $request->query->getInt('page', 1)),
        ]);
    }
}
