<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\ApplyDealTier;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\DealTierNotApplicable;
use App\Crm\Exception\StaleProperty;
use App\Crm\Form\ReturnPath;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Tier auf Location anwenden" (CRM-8, D20), one plain form with PRG: the record's menu posts it, and so does the
 * board's won prompt. `expected` is the code of the venue's tier the prompt showed, `return_to` goes through
 * `ReturnPath` (P11).
 * A refused command rolled back, so it leaves no audit row.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealTierController extends AbstractController
{
    /** The token id of the form, a plain one because the board builds it from the stage answer. */
    public const string TOKEN = 'submit';

    /** What `chk_tiers_code` lets a code be. A well-formed code that is no tier's is stale against the venue's, so the shape is all this checks. */
    private const string CODE_SHAPE = '/\A[a-z][a-z0-9_]*\z/';

    #[Route('/admin/crm/deals/{id}/tier', name: 'admin_crm_deals_apply_tier', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $back = ReturnPath::safe($input['return_to'] ?? null, $this->generateUrl('admin_crm_deals_show', ['id' => $id]));
        $token = $input['_token'] ?? null;
        $expected = \is_string($input['expected'] ?? null) && 1 === preg_match(self::CODE_SHAPE, $input['expected']) ? $input['expected'] : null;

        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token) || null === $expected) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        try {
            $commandBus->dispatch(new ApplyDealTier($id, $expected));
            $this->addFlash('success', CrmFlash::message('deal.tier.flash.applied'));
        } catch (HandlerFailedException $failure) {
            $this->addFlash('error', CrmFlash::message(match (true) {
                [] !== $failure->getWrappedExceptions(StaleProperty::class) => 'deal.tier.flash.stale',
                [] !== $failure->getWrappedExceptions(DealTierNotApplicable::class) => 'deal.tier.flash.gone',
                [] !== $failure->getWrappedExceptions(DealNotFound::class) => throw $this->createNotFoundException(),
                default => throw $failure,
            }));
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }
}
