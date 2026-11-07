<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\Form\VenueCardSaver;
use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueEditQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueCardSaveOutcome;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Form\LocationForms;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\LocationCard;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The manager's editor (POR-8): one page of cards, each with its own form, POST route and `Venues` command, saved
 * by staff's `VenueCardSaver`. The voter decides access. A save redirects to `…/{number}#<anchor>`; a failed one
 * re-renders the page with that card bound (422, or 409 for a stale save).
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[Route('/konto/locations/{number}', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class LocationEditController extends AbstractController
{
    public function __construct(
        private readonly VenueEditQuery $edits,
        private readonly LocationForms $forms,
        private readonly VenueCardSaver $saver,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
        private readonly SubmissionQuery $submissions,
        private readonly LocationPlanQuery $plans,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'portal_location', methods: ['GET'])]
    public function show(int $number): Response
    {
        return $this->renderEditor($this->venue($number));
    }

    #[Route('/texte', name: 'portal_location_texts', methods: ['POST'])]
    public function texts(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Texts, $number, $request);
    }

    #[Route('/adresse', name: 'portal_location_address', methods: ['POST'])]
    public function address(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Address, $number, $request);
    }

    #[Route('/merkmale', name: 'portal_location_terms', methods: ['POST'])]
    public function terms(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Terms, $number, $request);
    }

    #[Route('/veranstaltung', name: 'portal_location_event', methods: ['POST'])]
    public function event(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Event, $number, $request);
    }

    #[Route('/kontakt', name: 'portal_location_contact', methods: ['POST'])]
    public function contact(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Contact, $number, $request);
    }

    #[Route('/faq', name: 'portal_location_faq', methods: ['POST'])]
    public function faq(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Faq, $number, $request);
    }

    #[Route('/gaeste', name: 'portal_location_capacity', methods: ['POST'])]
    public function capacity(int $number, Request $request): Response
    {
        return $this->save(LocationCard::Capacity, $number, $request);
    }

    private function save(LocationCard $card, int $number, Request $request): Response
    {
        $edit = $this->venue($number);
        $form = $this->forms->form($card, $edit);
        $form->handleRequest($request);

        return match ($this->saver->save($form, $number, $edit)) {
            VenueCardSaveOutcome::Invalid => $this->renderEditor($edit, $card, $form, Response::HTTP_UNPROCESSABLE_ENTITY),
            VenueCardSaveOutcome::Stale => $this->renderEditor($edit, $card, $form, Response::HTTP_CONFLICT, stale: true),
            VenueCardSaveOutcome::Unchanged => $this->savedTo($card, $number, 'editor.unchanged'),
            VenueCardSaveOutcome::Saved => $this->savedTo($card, $number, 'editor.saved'),
        };
    }

    private function venue(int $number): VenueEdit
    {
        return $this->edits->find($number) ?? throw $this->createNotFoundException();
    }

    private function savedTo(LocationCard $card, int $number, string $flash): RedirectResponse
    {
        $this->addFlash('success', CmsFlash::message($flash, ['card' => CmsFlash::message('editor.card.'.$card->value)]));

        return $this->redirect($this->generateUrl('portal_location', ['number' => $number]).'#'.$card->anchor());
    }

    /**
     * @param FormInterface<mixed>|null $boundForm
     */
    private function renderEditor(VenueEdit $edit, ?LocationCard $bound = null, ?FormInterface $boundForm = null, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        $locales = [];
        foreach ($this->locales->all() as $locale) {
            $locales[] = ['code' => $locale, 'label' => $this->translator->trans('editor.locale.'.$locale, [], 'cms')];
        }

        return $this->render('portal/location/edit.html.twig', [
            'venue' => $edit->header,
            'submission' => $this->submissions->find($edit->header->id),
            'plan' => $this->plans->forVenue($edit->header->id, $this->clock->now()),
            'edit' => $edit,
            'forms' => $this->forms->views($edit, $bound, $boundForm),
            'offline_form' => $this->forms->offlineForm($edit->number())->createView(),
            'bound_card' => $bound?->value,
            'stale' => $stale,
            'cards' => LocationCard::cases(),
            'locales' => $locales,
        ], new Response(status: $status));
    }
}
