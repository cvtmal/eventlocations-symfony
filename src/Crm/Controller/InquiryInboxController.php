<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\InquiryBadge;
use App\Crm\ReadModel\InquiryDetail;
use App\Crm\ReadModel\InquiryInboxQuery;
use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\ReadModel\InquiryFeedbackQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The inbox (CRM-9, N4): a status tab's list beside one inquiry in full. `index` opens the tab's first row, `show` the
 * chosen one, which need not be in the tab: the row that was just marked done stays open. An absent or unknown `status`
 * is the default tab "Neu". Neither GET writes, a look at an inquiry leaves its status alone (`InquiryStatus`). Three
 * statements: the counts, which also feed the sidebar's badge, the rows and the inquiry.
 */
#[IsGranted('ROLE_EDITOR')]
final class InquiryInboxController extends AbstractController
{
    private const string ALL = 'all';

    public function __construct(
        private readonly InquiryInboxQuery $inbox,
        private readonly InquiryBadge $badge,
        private readonly InquiryFeedbackQuery $feedback,
    ) {
    }

    #[Route('/admin/crm/anfragen', name: 'admin_crm_inquiries', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    #[Route('/admin/crm/anfragen/{id}', name: 'admin_crm_inquiries_show', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function show(string $id, Request $request): Response
    {
        return $this->page($request, $this->inbox->detail($id) ?? throw $this->createNotFoundException());
    }

    /**
     * @param InquiryDetail|null $detail null opens the tab's first row
     */
    private function page(Request $request, ?InquiryDetail $detail): Response
    {
        $tab = ListQueryString::value($request, 'status');
        $status = self::ALL === $tab ? null : (InquiryStatus::tryFrom($tab) ?? InquiryStatus::New);

        $counts = $this->inbox->counts();
        $this->badge->remember($counts->new);
        $list = $this->inbox->page($status, ListQueryString::page($request), $counts);

        if (null === $detail && [] !== $list->rows) {
            $detail = $this->inbox->detail($list->rows[0]->id);
        }

        return $this->render('admin/crm/inquiry/index.html.twig', [
            'tab' => $status->value ?? self::ALL,
            'counts' => $counts,
            'list' => $list,
            'detail' => $detail,
            'feedback' => null === $detail ? null : $this->feedback->forInquiry($detail->id),
        ]);
    }
}
