<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\ReadModel\AdminUserListQuery;
use App\Admin\Security\AdminIdentity;
use App\Cms\Form\ModerationDecisionData;
use App\Cms\Form\ModerationDecisionType;
use App\Cms\ValueObject\CmsFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\DecideModerationCase;
use App\Venues\Command\TakeEntryOffline;
use App\Venues\Exception\ModerationCaseAlreadyDecided;
use App\Venues\Exception\ModerationCaseNotFound;
use App\Venues\Exception\ModerationCaseWithoutVenue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ReadModel\ModerationCaseQuery;
use App\Venues\ReadModel\ModerationCaseView;
use App\Venues\ValueObject\ModerationDecision;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One complaint (G2): everything it says, the photos it names, and staff's actions, each POST → redirect → GET with
 * a flash. "Eintrag offline nehmen" carries the token `cms_moderation` (a missing or wrong one changes nothing and
 * flashes `flash.action_failed`; `#[IsCsrfTokenValid]` would send a logged-in editor to the login page). The decision
 * is a form (note + one button per decision); an invalid note re-renders the page with 422.
 *
 * A case decided meanwhile (a double click, a second tab) is no error: the page shows the decision, with an info
 * (the handlers lock the case row, so the second request waits for the first and then finds it decided). An entry
 * that is offline already is not taken offline by this case: an info, not a success.
 * A `keep` that cannot put the entry back online (e.g. no gallery photo left) changes nothing and names the gaps.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/meldungen/{id}', requirements: ['id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class ModerationCaseController extends AbstractController
{
    public const string CSRF_ID = 'cms_moderation';

    public function __construct(
        private readonly ModerationCaseQuery $cases,
        private readonly AdminUserListQuery $staff,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_moderation_case', methods: ['GET'])]
    public function show(string $id): Response
    {
        $case = $this->find($id);

        return $this->page($case, $this->decisionForm($id));
    }

    #[Route('/offline', name: 'admin_moderation_case_offline', methods: ['POST'])]
    public function takeOffline(string $id, Request $request, #[CurrentUser] AdminIdentity $identity): Response
    {
        $this->find($id);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.action_failed');

            return $this->back($id);
        }

        try {
            $envelope = $this->commandBus->dispatch(new TakeEntryOffline($id, $identity->id()));
        } catch (HandlerFailedException $failure) {
            return $this->refused($id, $failure);
        }
        if (true !== $envelope->last(HandledStamp::class)?->getResult()) {
            // The entry was offline already (another case, the CMS): this case did not take it offline.
            $this->addFlash('info', CmsFlash::message('moderation.flash.already_offline'));

            return $this->back($id);
        }
        $this->addFlash('success', CmsFlash::message('moderation.flash.offline'));

        return $this->back($id);
    }

    #[Route('/entscheiden', name: 'admin_moderation_case_decide', methods: ['POST'])]
    public function decide(string $id, Request $request, #[CurrentUser] AdminIdentity $identity): Response
    {
        $case = $this->find($id);
        $form = $this->decisionForm($id);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->back($id);
        }
        if (!$form->isValid()) {
            return $this->page($case, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $decision = $this->clicked($form);
        if (null === $decision) {
            $this->addFlash('error', 'flash.action_failed');

            return $this->back($id);
        }
        $data = $form->getData();
        \assert($data instanceof ModerationDecisionData);

        try {
            $this->commandBus->dispatch(new DecideModerationCase($id, $decision, $data->note, $identity->id()));
        } catch (HandlerFailedException $failure) {
            return $this->refused($id, $failure);
        }
        $this->addFlash('success', CmsFlash::message('moderation.flash.decided.'.$decision->value));

        return $this->back($id);
    }

    /**
     * The decision of the button that sent the form; none without a button (a crafted post), never a default.
     *
     * @param FormInterface<mixed> $form
     */
    private function clicked(FormInterface $form): ?ModerationDecision
    {
        foreach (ModerationDecision::cases() as $decision) {
            $button = $form->get($decision->value);
            if ($button instanceof ClickableInterface && $button->isClicked()) {
                return $decision;
            }
        }

        return null;
    }

    private function find(string $id): ModerationCaseView
    {
        return $this->cases->find($id) ?? throw $this->createNotFoundException();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function decisionForm(string $id): FormInterface
    {
        return $this->createForm(ModerationDecisionType::class, new ModerationDecisionData(), [
            'action' => $this->generateUrl('admin_moderation_case_decide', ['id' => $id]),
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function page(ModerationCaseView $case, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $decidedBy = null === $case->decidedBy ? null : $this->staff->find($case->decidedBy)?->displayName;

        return $this->render('admin/cms/moderation/case.html.twig', [
            'case' => $case,
            'form' => $form,
            'decided_by' => $decidedBy,
            'csrf' => self::CSRF_ID,
        ], new Response(status: $status));
    }

    private function refused(string $id, HandlerFailedException $failure): RedirectResponse
    {
        if ([] !== $failure->getWrappedExceptions(ModerationCaseNotFound::class)) {
            throw $this->createNotFoundException();
        }
        if ([] !== $failure->getWrappedExceptions(ModerationCaseAlreadyDecided::class)) {
            $this->addFlash('info', CmsFlash::message('moderation.flash.already_decided'));

            return $this->back($id);
        }
        if ([] !== $failure->getWrappedExceptions(ModerationCaseWithoutVenue::class)) {
            $this->addFlash('error', CmsFlash::message('moderation.flash.no_venue'));

            return $this->back($id);
        }
        $violation = VenueRuleViolation::unwrap($failure);
        $missing = implode(', ', array_map(fn (string $code): string => $this->translator->trans('editor.checklist.item.'.$code, [], 'cms'), $violation->missing));
        $this->addFlash('error', CmsFlash::message('moderation.flash.not_publishable', ['missing' => $missing]));

        return $this->back($id);
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute('admin_moderation_case', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
