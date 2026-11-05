<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\CsvStream;
use App\Crm\ReadModel\ContactCsvQuery;
use App\Crm\ReadModel\ContactCsvRow;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every contact row as a CSV (CRM-6, D20, admins only), with its Location, for the newsletter (#55 D7). No filter:
 * the contacts have no list of their own any more. The static path wins over the redirect of `/admin/crm/kontakte/{id}`.
 */
#[IsGranted('ROLE_ADMIN')]
final class ContactExportController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    #[Route('/admin/crm/kontakte/export', name: 'admin_crm_contacts_export', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        ContactCsvQuery $contacts,
        TranslatorInterface $translator,
        ClockInterface $clock,
        LoggerInterface $logger,
    ): Response {
        return CsvStream::response(
            'kontakte-'.$clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d').'.csv',
            [
                $translator->trans('contact.property.first_name', [], 'crm'),
                $translator->trans('contact.property.last_name', [], 'crm'),
                $translator->trans('contact.property.company', [], 'crm'),
                $translator->trans('contact.property.position', [], 'crm'),
                $translator->trans('contact.property.email', [], 'crm'),
                $translator->trans('contact.property.phone', [], 'crm'),
            ],
            self::cells($contacts->rows()),
            $translator->trans('export.failed', [], 'crm'),
            $logger,
        );
    }

    /**
     * @param iterable<ContactCsvRow> $rows
     *
     * @return \Generator<list<string|null>>
     */
    private static function cells(iterable $rows): \Generator
    {
        foreach ($rows as $row) {
            yield [$row->firstName, $row->lastName, $row->companyName, $row->position, $row->email, $row->phone];
        }
    }
}
