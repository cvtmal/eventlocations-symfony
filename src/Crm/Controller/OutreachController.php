<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\RecordCampaignSent;
use App\Crm\Command\SuppressEmail;
use App\Crm\CsvStream;
use App\Crm\Form\PropertyConstraints;
use App\Crm\ReadModel\CampaignPrintQuery;
use App\Crm\ReadModel\CampaignPrintRow;
use App\Crm\ReadModel\CohortFunnelQuery;
use App\Crm\ReadModel\OutreachQuery;
use App\Crm\ReadModel\SuppressionQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Funnel\CampaignCode;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\IssueVenuePreview;
use App\Venues\PreviewToken;
use App\Venues\ValueObject\PreviewShortCode;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The CRM's "Akquise" page (G11): the funnel by cohort, the postal campaigns (print list, send day) and the suppression
 * list. The print list is a POST because it issues the missing preview links (180 days, the Location's campaign code)
 * before it streams the mail-merge CSV; it is a file for a letter shop or a mail merge, never a mailer (D30). Every
 * export of the CRM leaves out a suppressed Location; this one also leaves out a returned letter and a Location without
 * an Eintrag.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/crm/akquise', options: [LocalizedRoutes::UNLOCALIZED => true])]
final class OutreachController extends AbstractController
{
    public const string TOKEN = 'crm-outreach';

    /** How long a link issued for a print list lives. */
    public const int PRINT_LINK_DAYS = 180;

    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'admin_crm_outreach', methods: ['GET'])]
    public function index(CohortFunnelQuery $funnel, OutreachQuery $outreach, SuppressionQuery $suppressions): Response
    {
        return $this->render('admin/crm/outreach/index.html.twig', [
            'funnel' => $funnel->funnel(),
            'campaigns' => $outreach->campaigns(),
            'suppressions' => $suppressions->newest(),
            'suppression_count' => $suppressions->count(),
            'token' => self::TOKEN,
            'today' => $this->clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d'),
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/druckliste', name: 'admin_crm_outreach_print', methods: ['POST'])]
    public function print(Request $request, CampaignPrintQuery $print, PreviewToken $tokens, TranslatorInterface $translator, LoggerInterface $logger): Response
    {
        $code = $this->campaign($request);
        if (null === $code) {
            return $this->back();
        }

        $now = $this->clock->now();
        foreach ($print->missingLinks($code, $now) as $number) {
            $this->commandBus->dispatch(new IssueVenuePreview($number, $code, self::PRINT_LINK_DAYS));
        }
        $zone = new \DateTimeZone(self::ZONE);
        $rows = $print->rows($code, $now);

        return CsvStream::response(
            'druckliste-'.$code.'-'.$now->setTimezone($zone)->format('Y-m-d').'.csv',
            array_map(static fn (string $key): string => $translator->trans('outreach.print.column.'.$key, [], 'crm'), ['company', 'contact', 'address', 'venue', 'street', 'zip', 'town', 'cohort', 'short_link', 'link', 'valid_until']),
            (function () use ($rows, $tokens, $zone): \Generator {
                foreach ($rows as $row) {
                    yield $this->cells($row, $tokens, $zone);
                }
            })(),
            $translator->trans('export.failed', [], 'crm'),
            $logger,
        );
    }

    #[Route('/versand', name: 'admin_crm_outreach_sent', methods: ['POST'])]
    public function sent(Request $request): RedirectResponse
    {
        $code = $this->campaign($request);
        $day = TaskDueDate::parse(self::field($request, 'sent_on'));
        if (null === $code) {
            return $this->back();
        }
        if (null === $day) {
            $this->addFlash('error', CrmFlash::message('outreach.flash.date_invalid'));

            return $this->back();
        }

        $stamped = $this->commandBus->dispatch(new RecordCampaignSent($code, $day))->last(HandledStamp::class)?->getResult();
        $this->addFlash('success', CrmFlash::message('outreach.flash.sent', ['count' => (int) $stamped, 'campaign' => $code]));

        return $this->back();
    }

    #[Route('/sperrliste', name: 'admin_crm_outreach_suppress', methods: ['POST'])]
    public function suppress(Request $request, ValidatorInterface $validator): RedirectResponse
    {
        if (!$this->tokenValid($request)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->back('sperrliste');
        }
        $email = self::field($request, 'email');
        if (\count($validator->validate($email, [new Assert\NotBlank(), ...PropertyConstraints::emailCc()])) > 0) {
            $this->addFlash('error', CrmFlash::message('outreach.flash.email_invalid'));

            return $this->back('sperrliste');
        }

        $this->commandBus->dispatch(new SuppressEmail($email));
        $this->addFlash('success', CrmFlash::message('outreach.flash.email_suppressed'));

        return $this->back('sperrliste');
    }

    /**
     * @return list<string|null>
     */
    private function cells(CampaignPrintRow $row, PreviewToken $tokens, \DateTimeZone $zone): array
    {
        return [
            $row->company,
            $row->contact,
            $row->address,
            $row->venue,
            $row->street,
            $row->zip,
            $row->town,
            $row->cohort,
            $this->generateUrl('venue_preview_short', ['code' => PreviewShortCode::display($row->shortCode)], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->generateUrl('venue_preview', ['token' => $tokens->forId($row->linkId)], UrlGeneratorInterface::ABSOLUTE_URL),
            $row->expiresAt->setTimezone($zone)->format('d.m.Y'),
        ];
    }

    /**
     * The posted campaign code when the CSRF token holds and the code is plain; a flash and null otherwise.
     */
    private function campaign(Request $request): ?string
    {
        if (!$this->tokenValid($request)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return null;
        }
        $code = self::field($request, 'campaign');
        if (!CampaignCode::isValid($code)) {
            $this->addFlash('error', CrmFlash::message('outreach.flash.campaign_invalid'));

            return null;
        }

        return $code;
    }

    private function tokenValid(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::TOKEN, self::field($request, '_token'));
    }

    private static function field(Request $request, string $name): string
    {
        $value = $request->request->all()[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function back(string $fragment = 'kampagnen'): RedirectResponse
    {
        return $this->redirectToRoute('admin_crm_outreach', ['_fragment' => $fragment], Response::HTTP_SEE_OTHER);
    }
}
