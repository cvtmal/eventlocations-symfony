<?php

declare(strict_types=1);

namespace App\Cms;

use App\Cms\Form\DocumentTitleData;
use App\Cms\Form\DocumentTitleType;
use App\Cms\Form\DocumentUploadData;
use App\Cms\Form\DocumentUploadType;
use App\Cms\ReadModel\ManagedDocument;
use App\Cms\ReadModel\VenueDocumentsQuery;
use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\DocumentPagesSetup;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\RemoveVenueDocument;
use App\Venues\Command\ReorderVenueDocuments;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The Dokumente pages (#25, CMS-7, D22), shared by staff's `Cms\Controller\VenueDocumentsController` and the manager's
 * `Portal\Controller\LocationDocumentsController` (F11), which keep access and routes; `DocumentPagesSetup` names the
 * host's routes, templates and extra context. A server page without JavaScript: the list reorders by move buttons (one
 * POST route per direction, PF12) and removes through a confirm dialog; the upload card adds a PDF with its titles; each
 * document's titles have their own page with the row token (PF2). Every write is POST → redirect → GET with a flash;
 * invalid input is a 422 with the values kept, a stale save a 409. A PDF over the plan's limit is the handler's 422
 * (`TierLimits`, F12); every write purges the venue's HTTP cache after the commit (its Venues handler).
 *
 * The move and remove forms carry the host's token id (`DocumentPagesSetup::$csrfId`). A missing or wrong one changes nothing and flashes
 * `flash.action_failed`, as the record header's actions do (`#[IsCsrfTokenValid]` would send a logged-in editor to the
 * login page: `AdminEntryPoint` answers every HTML authentication failure so).
 */
final readonly class VenueDocumentPages
{
    public function __construct(
        private VenueHeaderQuery $headers,
        private VenueDocumentsQuery $documents,
        private MessageBusInterface $commandBus,
        private TranslatorInterface $translator,
        private EnabledLocale $locales,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
        private RequestStack $requests,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function index(DocumentPagesSetup $setup, int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        $form = $this->forms->create(DocumentUploadType::class, new DocumentUploadData(), [
            'action' => $this->urls->generate($setup->route, ['number' => $number]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderList($setup, $venue, $form);
        }
        if (!$form->isValid()) {
            return $this->renderList($setup, $venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Only a well-formed upload (token, file, titles) spends the host's budget.
        $refusal = null === $setup->admitUpload ? null : ($setup->admitUpload)();
        if (null !== $refusal) {
            $form->get('file')->addError(new FormError($this->translator->trans($refusal, [], 'validators')));

            return $this->renderList($setup, $venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof DocumentUploadData);
        try {
            $this->commandBus->dispatch($data->toCommand($number));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            $this->addViolation($form, VenueRuleViolation::unwrap($failure));

            return $this->renderList($setup, $venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $title = $data->titles[$this->locales->default()] ?? '';
        $this->flash('success', CmsFlash::message('documents.flash.added', ['title' => trim($title)]));

        return $this->backToList($setup, $number);
    }

    public function edit(DocumentPagesSetup $setup, int $number, string $id, Request $request): Response
    {
        $venue = $this->venue($number);
        $document = $this->documents->document($number, $id) ?? throw new NotFoundHttpException();
        $form = $this->forms->create(DocumentTitleType::class, DocumentTitleData::from($document), [
            'action' => $this->urls->generate($setup->route.'_edit', ['number' => $number, 'id' => $id]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderEdit($setup, $venue, $document, $form);
        }
        if (!$form->isValid()) {
            return $this->renderEdit($setup, $venue, $document, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof DocumentTitleData);
        $title = $this->title($document);
        if (self::titles($data->titles) === self::titles($document->titles)) {
            $this->flash('success', CmsFlash::message('documents.flash.unchanged', ['title' => $title]));

            return $this->backToList($setup, $number);
        }

        try {
            $this->commandBus->dispatch($data->toCommand($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            if ([] !== $failure->getWrappedExceptions(StaleEdit::class)) {
                $form->addError(new FormError($this->translator->trans('error.stale', [], 'cms')));

                return $this->renderEdit($setup, $venue, $document, $form, Response::HTTP_CONFLICT, stale: true);
            }
            $this->addViolation($form, VenueRuleViolation::unwrap($failure));

            return $this->renderEdit($setup, $venue, $document, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->flash('success', CmsFlash::message('documents.flash.saved', ['title' => trim($data->titles[$this->locales->default()] ?? '')]));

        return $this->backToList($setup, $number);
    }

    public function remove(DocumentPagesSetup $setup, int $number, string $id, Request $request): Response
    {
        $this->venue($number);
        $document = $this->documents->document($number, $id) ?? throw new NotFoundHttpException();
        if (!$this->tokenIsValid($setup, $request)) {
            return $this->backToList($setup, $number);
        }

        try {
            $this->commandBus->dispatch(new RemoveVenueDocument($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->flash('success', CmsFlash::message('documents.flash.removed', ['title' => $this->title($document)]));

        return $this->backToList($setup, $number);
    }

    /**
     * Swaps the document with its neighbour (`$step` -1 or 1) and sends the whole order (D11); a move past either end
     * is a no-op, as the list renders those buttons `aria-disabled`.
     */
    public function move(DocumentPagesSetup $setup, int $number, string $id, int $step, Request $request): Response
    {
        $this->venue($number);
        $documents = $this->documents->forVenue($number) ?? throw new NotFoundHttpException();
        $ids = array_map(static fn (ManagedDocument $document): string => $document->id, $documents);
        $index = array_search($id, $ids, true);
        if (false === $index) {
            throw new NotFoundHttpException();
        }
        if (!$this->tokenIsValid($setup, $request)) {
            return $this->backToList($setup, $number);
        }

        $target = $index + $step;
        if (!isset($ids[$target])) {
            return $this->backToList($setup, $number);
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        try {
            $this->commandBus->dispatch(new ReorderVenueDocuments($number, array_values($ids)));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            if ([] === $failure->getWrappedExceptions(StaleEdit::class)) {
                throw $failure;
            }
            $this->flash('error', CmsFlash::message('error.stale'));

            return $this->backToList($setup, $number);
        }
        $this->flash('success', CmsFlash::message('documents.flash.moved', ['title' => $this->title($documents[$index]), 'position' => $target + 1]));

        return new RedirectResponse($this->urls->generate($setup->route, ['number' => $number, '_fragment' => 'venue-documents']));
    }

    private function venue(int $number): VenueHeader
    {
        return $this->headers->find($number) ?? throw new NotFoundHttpException();
    }

    private function tokenIsValid(DocumentPagesSetup $setup, Request $request): bool
    {
        if ($this->csrf->isTokenValid(new CsrfToken($setup->csrfId, $request->getPayload()->getString('_token')))) {
            return true;
        }
        $this->flash('error', 'flash.action_failed');

        return false;
    }

    private function flash(string $type, mixed $message): void
    {
        $session = $this->requests->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add($type, $message);
    }

    private function rethrowNotFound(HandlerFailedException $failure): void
    {
        if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
            throw new NotFoundHttpException();
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

    private function backToList(DocumentPagesSetup $setup, int $number): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate($setup->route, ['number' => $number]));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderList(DocumentPagesSetup $setup, VenueHeader $venue, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render($setup->listTemplate, ($setup->context)($venue) + [
            'venue' => $venue,
            'documents' => $this->documents->forVenue($venue->number) ?? [],
            'form' => $form->createView(),
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'csrf_id' => $setup->csrfId,
            'documents_route' => $setup->route,
        ], $status);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderEdit(DocumentPagesSetup $setup, VenueHeader $venue, ManagedDocument $document, FormInterface $form, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        return $this->render($setup->editTemplate, ($setup->context)($venue) + [
            'venue' => $venue,
            'document' => $document,
            'title' => $this->title($document),
            'form' => $form->createView(),
            'locale_codes' => $this->locales->all(),
            'stale' => $stale,
            'documents_route' => $setup->route,
        ], $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status): Response
    {
        return new Response($this->twig->render($template, $context), $status);
    }
}
