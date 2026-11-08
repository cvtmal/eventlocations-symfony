<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Form\LastMinuteOfferData;
use App\Portal\Form\LastMinuteOfferType;
use App\Portal\Form\LocationForms;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\EndLastMinuteOffer;
use App\Venues\Exception\LastMinuteOfferRefused;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ReadModel\LastMinuteOffersQuery;
use App\Venues\ValueObject\LastMinuteLimits;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The manager's tab «Kurzfristig frei» (G14, D55): the venue's live offers with «Beenden», and the form for a new one
 * when the plan allows offers (Pro); below Pro the upsell and the plan card with «Pro anfragen». A POST on a venue
 * without Pro reaches the handler like any other and is refused there (422). Saving needs the `/konto` session: there
 * is no link that writes without a login (D55).
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[Route('/konto/locations/{number}/kurzfristig-frei', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class LocationLastMinuteController extends AbstractController
{
    public const string CSRF_ID = 'portal_last_minute';

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly LastMinuteOffersQuery $offers,
        private readonly LocationForms $forms,
        private readonly SubmissionQuery $submissions,
        private readonly LocationPlanQuery $plans,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'portal_location_last_minute', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $choices = $this->offers->choices($venue->id, $this->clock->now());
        $form = $this->createForm(LastMinuteOfferType::class, new LastMinuteOfferData(), [
            'rooms' => $choices['rooms'],
            'event_types' => $choices['eventTypes'],
        ])->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderTab($venue, $form);
        }
        if (!$form->isValid()) {
            return $this->renderTab($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof LastMinuteOfferData);
        try {
            $this->commandBus->dispatch($data->toCommand($number));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
                throw $this->createNotFoundException();
            }
            $this->addRefusal($form, $failure);

            return $this->renderTab($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', PortalFlash::message('last_minute.flash.published'));

        return $this->redirectToRoute('portal_location_last_minute', ['number' => $number], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/beenden', name: 'portal_location_last_minute_end', methods: ['POST'])]
    public function end(int $number, string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', PortalFlash::message('last_minute.flash.failed'));

            return $this->redirectToRoute('portal_location_last_minute', ['number' => $number], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->commandBus->dispatch(new EndLastMinuteOffer($number, $id));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
                throw $this->createNotFoundException();
            }

            throw $failure;
        }
        $this->addFlash('success', PortalFlash::message('last_minute.flash.ended'));

        return $this->redirectToRoute('portal_location_last_minute', ['number' => $number], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderTab(VenueHeader $venue, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $now = $this->clock->now();

        return $this->render('portal/location/last_minute.html.twig', [
            'venue' => $venue,
            'submission' => $this->submissions->find($venue->id),
            'offline_form' => $this->forms->offlineForm($venue->number)->createView(),
            'plan' => $this->plans->forVenue($venue->id, $now),
            'offers' => $this->offers->forVenue($venue->id, $now),
            'offer_form' => $form->createView(),
            'max_live' => LastMinuteLimits::MAX_LIVE,
            'horizon_days' => LastMinuteLimits::HORIZON_DAYS,
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: $status));
    }

    /**
     * A refusal of the handler on its field, or on the form when it names none (the plan rules). Anything else is
     * rethrown.
     *
     * @param FormInterface<mixed> $form
     */
    private function addRefusal(FormInterface $form, HandlerFailedException $failure): void
    {
        $refusal = LastMinuteOfferRefused::within($failure);
        if (null !== $refusal) {
            $message = $this->translator->trans($refusal->translationKey(), $refusal->parameters, 'cms');
            $target = $form->has($refusal->field) ? $form->get($refusal->field) : $form;
            $target->addError(new FormError($message));

            return;
        }

        $violation = VenueRuleViolation::unwrap($failure);
        $form->addError(new FormError($this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')));
    }
}
