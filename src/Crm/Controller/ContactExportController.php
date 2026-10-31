<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\CsvStream;
use App\Crm\Form\ContactFilterData;
use App\Crm\Form\ContactFilterType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\ContactCsvRow;
use App\Crm\ReadModel\ContactListQuery;
use App\Crm\ReadModel\ContactSort;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contact list as a CSV (CRM-6, D20, admins only): the list's filters and order, every page. An
 * invalid filter goes back to the list, so its 422 notice stays in one place (P14).
 */
#[IsGranted('ROLE_ADMIN')]
final class ContactExportController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    #[Route('/admin/crm/kontakte/export', name: 'admin_crm_contacts_export', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        Request $request,
        ContactListQuery $contacts,
        OwnerOptionsQuery $owners,
        FormFactoryInterface $forms,
        TranslatorInterface $translator,
        ClockInterface $clock,
        LoggerInterface $logger,
    ): Response {
        $data = new ContactFilterData();
        $form = $forms->createNamed('', ContactFilterType::class, $data, ['owners' => $owners->all()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && !$form->isValid()) {
            return $this->redirectToRoute('admin_crm_contacts', $request->query->all(), Response::HTTP_SEE_OTHER);
        }

        $sort = ContactSort::tryFrom(ListQueryString::value($request, 'sort')) ?? ContactSort::DEFAULT;
        $direction = SortDirection::tryFrom(ListQueryString::value($request, 'dir')) ?? SortDirection::Asc;

        return CsvStream::response(
            'kontakte-'.$clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d').'.csv',
            [
                $translator->trans('contact.property.first_name', [], 'crm'),
                $translator->trans('contact.property.last_name', [], 'crm'),
                $translator->trans('contact.property.kind', [], 'crm'),
                $translator->trans('contact.property.company', [], 'crm'),
                $translator->trans('contact.property.position', [], 'crm'),
                $translator->trans('contact.property.email', [], 'crm'),
                $translator->trans('contact.property.phone', [], 'crm'),
                $translator->trans('contact.property.owner', [], 'crm'),
            ],
            self::cells($contacts->csvRows($data->toFilter(), $sort, $direction), $translator),
            $translator->trans('export.failed', [], 'crm'),
            $logger,
        );
    }

    /**
     * @param iterable<ContactCsvRow> $rows
     *
     * @return \Generator<list<string|null>>
     */
    private static function cells(iterable $rows, TranslatorInterface $translator): \Generator
    {
        foreach ($rows as $row) {
            yield [
                $row->firstName,
                $row->lastName,
                $row->kind->trans($translator),
                $row->companyName,
                $row->position,
                $row->email,
                $row->phone,
                $row->ownerName,
            ];
        }
    }
}
