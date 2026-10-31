<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Form\DocumentTitleData;
use App\Cms\Form\DocumentTitleType;
use App\Cms\Form\DocumentUploadData;
use App\Cms\Form\DocumentUploadType;
use App\Cms\ReadModel\ManagedDocument;
use App\Cms\ReadModel\VenueDocumentsQuery;
use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\RemoveVenueDocument;
use App\Venues\Command\ReorderVenueDocuments;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
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
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Dokumente tab (#25, CMS-7, D22): a server page without JavaScript. The list reorders by move buttons
 * (one POST route per direction, PF12) and removes through a confirm dialog; the upload card adds a PDF with
 * its titles; each document's titles have their own page with the row token (PF2). Every write is
 * POST → redirect → GET with a flash; invalid input is a 422 with the values kept, a stale save a 409.
 *
 * The move and remove forms carry the token `cms_document`. A missing or wrong one changes nothing and
 * flashes `flash.action_failed`, as the record header's actions do (`#[IsCsrfTokenValid]` would send a
 * logged-in editor to the login page: `AdminEntryPoint` answers every HTML authentication failure so).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}/dokumente', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueDocumentsController extends AbstractController
{
    public const string CSRF_ID = 'cms_document';

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenueDocumentsQuery $documents,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('', name: 'admin_locations_documents', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        $form = $this->createForm(DocumentUploadType::class, new DocumentUploadData(), [
            'action' => $this->generateUrl('admin_locations_documents', ['number' => $number]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderList($venue, $form);
        }
        if (!$form->isValid()) {
            return $this->renderList($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof DocumentUploadData);
        try {
            $this->commandBus->dispatch($data->toCommand($number));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            $this->addViolation($form, VenueRuleViolation::unwrap($failure));

            return $this->renderList($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $title = $data->titles[$this->locales->default()] ?? '';
        $this->addFlash('success', CmsFlash::message('documents.flash.added', ['title' => trim($title)]));

        return $this->backToList($number);
    }

    #[Route('/{id}', name: 'admin_locations_documents_edit', methods: ['GET', 'POST'])]
    public function edit(int $number, string $id, Request $request): Response
    {
        $venue = $this->venue($number);
        $document = $this->documents->document($number, $id) ?? throw $this->createNotFoundException();
        $form = $this->createForm(DocumentTitleType::class, DocumentTitleData::from($document), [
            'action' => $this->generateUrl('admin_locations_documents_edit', ['number' => $number, 'id' => $id]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderEdit($venue, $document, $form);
        }
        if (!$form->isValid()) {
            return $this->renderEdit($venue, $document, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof DocumentTitleData);
        $title = $this->title($document);
        if (self::titles($data->titles) === self::titles($document->titles)) {
            $this->addFlash('success', CmsFlash::message('documents.flash.unchanged', ['title' => $title]));

            return $this->backToList($number);
        }

        try {
            $this->commandBus->dispatch($data->toCommand($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            if ([] !== $failure->getWrappedExceptions(StaleEdit::class)) {
                $form->addError(new FormError($this->translator->trans('error.stale', [], 'cms')));

                return $this->renderEdit($venue, $document, $form, Response::HTTP_CONFLICT, stale: true);
            }
            $this->addViolation($form, VenueRuleViolation::unwrap($failure));

            return $this->renderEdit($venue, $document, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', CmsFlash::message('documents.flash.saved', ['title' => trim($data->titles[$this->locales->default()] ?? '')]));

        return $this->backToList($number);
    }

    #[Route('/{id}/nach-oben', name: 'admin_locations_documents_up', methods: ['POST'])]
    public function up(int $number, string $id, Request $request): Response
    {
        return $this->move($number, $id, -1, $request);
    }

    #[Route('/{id}/nach-unten', name: 'admin_locations_documents_down', methods: ['POST'])]
    public function down(int $number, string $id, Request $request): Response
    {
        return $this->move($number, $id, 1, $request);
    }

    #[Route('/{id}/loeschen', name: 'admin_locations_documents_remove', methods: ['POST'])]
    public function remove(int $number, string $id, Request $request): Response
    {
        $this->venue($number);
        $document = $this->documents->document($number, $id) ?? throw $this->createNotFoundException();
        if (!$this->tokenIsValid($request)) {
            return $this->backToList($number);
        }

        try {
            $this->commandBus->dispatch(new RemoveVenueDocument($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->addFlash('success', CmsFlash::message('documents.flash.removed', ['title' => $this->title($document)]));

        return $this->backToList($number);
    }

    /**
     * Swaps the document with its neighbour and sends the whole order (D11); a move past either end is a
     * no-op, as the list renders those buttons `aria-disabled`.
     */
    private function move(int $number, string $id, int $step, Request $request): Response
    {
        $this->venue($number);
        $documents = $this->documents->forVenue($number) ?? throw $this->createNotFoundException();
        $ids = array_map(static fn (ManagedDocument $document): string => $document->id, $documents);
        $index = array_search($id, $ids, true);
        if (false === $index) {
            throw $this->createNotFoundException();
        }
        if (!$this->tokenIsValid($request)) {
            return $this->backToList($number);
        }

        $target = $index + $step;
        if (!isset($ids[$target])) {
            return $this->backToList($number);
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        try {
            $this->commandBus->dispatch(new ReorderVenueDocuments($number, array_values($ids)));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            if ([] === $failure->getWrappedExceptions(StaleEdit::class)) {
                throw $failure;
            }
            $this->addFlash('error', CmsFlash::message('error.stale'));

            return $this->backToList($number);
        }
        $this->addFlash('success', CmsFlash::message('documents.flash.moved', ['title' => $this->title($documents[$index]), 'position' => $target + 1]));

        return $this->redirectToRoute('admin_locations_documents', ['number' => $number, '_fragment' => 'venue-documents']);
    }

    private function venue(int $number): VenueHeader
    {
        return $this->headers->find($number) ?? throw $this->createNotFoundException();
    }

    private function tokenIsValid(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'flash.action_failed');

        return false;
    }

    private function rethrowNotFound(HandlerFailedException $failure): void
    {
        if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * A handler's refusal goes to its field (`file`, `titles.<locale>`), else to the form.
     *
     * @param FormInterface<mixed> $form
     */
    private function addViolation(FormInterface $form, VenueRuleViolation $violation): void
    {
        $target = $form;
        foreach (null === $violation->field ? [] : explode('.', $violation->field) as $name) {
            if (!$target->has($name)) {
                $target = $form;
                break;
            }
            $target = $target->get($name);
        }
        $target->addError(new FormError($this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')));
    }

    private function title(ManagedDocument $document): string
    {
        return $document->title($this->locales->default(), $this->locales->default());
    }

    /**
     * Titles as the handler stores them: trimmed, empty ones absent.
     *
     * @param array<string, ?string> $titles
     *
     * @return array<string, string>
     */
    private static function titles(array $titles): array
    {
        $kept = array_filter(array_map(static fn (?string $title): string => trim($title ?? ''), $titles), static fn (string $title): bool => '' !== $title);
        ksort($kept);

        return $kept;
    }

    private function backToList(int $number): RedirectResponse
    {
        return $this->redirectToRoute('admin_locations_documents', ['number' => $number]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderList(VenueHeader $venue, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/cms/venue/documents.html.twig', [
            'venue' => $venue,
            'documents' => $this->documents->forVenue($venue->number) ?? [],
            'form' => $form->createView(),
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderEdit(VenueHeader $venue, ManagedDocument $document, FormInterface $form, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        return $this->render('admin/cms/venue/document_edit.html.twig', [
            'venue' => $venue,
            'document' => $document,
            'title' => $this->title($document),
            'form' => $form->createView(),
            'locale_codes' => $this->locales->all(),
            'stale' => $stale,
        ], new Response(status: $status));
    }
}
