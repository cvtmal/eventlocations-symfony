<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\ReadModel\AuditLogQuery;
use App\Cms\Form\ChangedFields;
use App\Cms\Form\VenueAddressData;
use App\Cms\Form\VenueCardData;
use App\Cms\Form\VenueEditorForms;
use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueEditQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueCard;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The venue editor (CMS-2): one page of cards, each with its own form, POST route and `Venues` command
 * (D9, D24). A save redirects to `…/{number}#<anchor>`; a failed one re-renders the page with that card
 * bound (422, or 409 for a stale save) and the others fresh. Input equal to what was loaded dispatches
 * nothing, so an idle click leaves no audit row and no `cms_edited_at` (D11).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueEditController extends AbstractController
{
    private const int HISTORY_LIMIT = 3;

    public function __construct(
        private readonly VenueEditQuery $edits,
        private readonly VenueEditorForms $forms,
        private readonly AuditLogQuery $auditLog,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('', name: 'admin_locations_edit', methods: ['GET'])]
    public function edit(int $number): Response
    {
        return $this->renderEditor($this->venue($number));
    }

    #[Route('/stammdaten', name: 'admin_locations_basics', methods: ['POST'])]
    public function basics(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Basics, $number, $request);
    }

    #[Route('/texte', name: 'admin_locations_texts', methods: ['POST'])]
    public function texts(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Texts, $number, $request);
    }

    #[Route('/adresse', name: 'admin_locations_address', methods: ['POST'])]
    public function address(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Address, $number, $request);
    }

    #[Route('/merkmale', name: 'admin_locations_terms', methods: ['POST'])]
    public function terms(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Terms, $number, $request);
    }

    #[Route('/hochzeit', name: 'admin_locations_wedding', methods: ['POST'])]
    public function wedding(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Wedding, $number, $request);
    }

    #[Route('/kontakt', name: 'admin_locations_contact', methods: ['POST'])]
    public function contact(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Contact, $number, $request);
    }

    #[Route('/faq', name: 'admin_locations_faq', methods: ['POST'])]
    public function faq(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Faq, $number, $request);
    }

    #[Route('/bewertung', name: 'admin_locations_rating', methods: ['POST'])]
    public function rating(int $number, Request $request): Response
    {
        return $this->save(VenueCard::Rating, $number, $request);
    }

    private function save(VenueCard $card, int $number, Request $request): Response
    {
        $edit = $this->venue($number);
        $form = $this->forms->form($card, $edit);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderEditor($edit, $card, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof VenueCardData);
        $changed = ChangedFields::between($card->dataClass()::from($edit), $data);
        $cardName = CmsFlash::message('editor.card.'.$card->value);

        if ([] === $changed && !($data instanceof VenueAddressData && $data->useTownCentre)) {
            $this->addFlash('success', CmsFlash::message('editor.unchanged', ['card' => $cardName]));

            return $this->backTo($card, $number);
        }

        try {
            $this->commandBus->dispatch($data->toCommand($number, $edit, $changed));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw $this->createNotFoundException();
            }
            if ([] !== $failure->getWrappedExceptions(StaleEdit::class)) {
                $form->addError(new FormError($this->translator->trans('error.stale', [], 'cms')));

                return $this->renderEditor($edit, $card, $form, Response::HTTP_CONFLICT, stale: true);
            }

            $violation = VenueRuleViolation::unwrap($failure);
            $target = null !== $violation->field && $form->has($violation->field) ? $form->get($violation->field) : $form;
            $target->addError(new FormError($this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')));

            return $this->renderEditor($edit, $card, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', CmsFlash::message('editor.saved', ['card' => $cardName]));

        return $this->backTo($card, $number);
    }

    /**
     * Verlauf: German labels per command (`history.command.<CommandName>`), because the audit log names the
     * PHP class. A command without a label (#25's, before it adds its own) reads as the fallback.
     *
     * @return list<array{actor: string, action: string, at: \DateTimeImmutable}>
     */
    private function history(int $number): array
    {
        $catalogue = $this->translator instanceof TranslatorBagInterface ? $this->translator->getCatalogue() : null;
        $entries = [];
        foreach ($this->auditLog->forSubject('venue', (string) $number, self::HISTORY_LIMIT) as $row) {
            $key = 'history.command.'.$row->commandName();
            $entries[] = [
                'actor' => $row->actorName ?? $this->translator->trans('history.actor.'.$row->actorType->value, [], 'cms'),
                'action' => $this->translator->trans(true === $catalogue?->has($key, 'cms') ? $key : 'history.command.fallback', [], 'cms'),
                'at' => $row->createdAt,
            ];
        }

        return $entries;
    }

    private function venue(int $number): VenueEdit
    {
        return $this->edits->find($number) ?? throw $this->createNotFoundException();
    }

    private function backTo(VenueCard $card, int $number): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin_locations_edit', ['number' => $number]).'#'.$card->anchor());
    }

    /**
     * @param FormInterface<mixed>|null $boundForm
     */
    private function renderEditor(VenueEdit $edit, ?VenueCard $bound = null, ?FormInterface $boundForm = null, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        $locales = [];
        foreach ($this->locales->all() as $locale) {
            $locales[] = ['code' => $locale, 'label' => $this->translator->trans('editor.locale.'.$locale, [], 'cms')];
        }

        return $this->render('admin/cms/venue/edit.html.twig', [
            'venue' => $edit->header,
            'edit' => $edit,
            'forms' => $this->forms->views($edit, $bound, $boundForm),
            'bound_card' => $bound?->value,
            'stale' => $stale,
            'locales' => $locales,
            'missing' => $edit->missingForPublish($this->locales->default()),
            'history' => $this->history($edit->number()),
            'cards' => VenueCard::cases(),
        ], new Response(status: $status));
    }
}
