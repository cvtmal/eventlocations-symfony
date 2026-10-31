<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Form\ReviewData;
use App\Cms\Form\ReviewType;
use App\Cms\ReadModel\ManagedReview;
use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueReviewsQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueCard;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\RemoveVenueReview;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
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
 * The Bewertungen tab (#25, CMS-8, D17): a server page listing the venue's reviews, a page to add one, a page
 * to edit one with its row token (PF2), and a confirm dialog to remove one. Score, count and highlights stay
 * in the editor's Bewertung card; the list links there. Every write is POST → redirect → GET with a flash;
 * invalid input is a 422 with the values kept, a stale save a 409.
 *
 * The remove forms carry the token `cms_review`; a missing or wrong one changes nothing and flashes
 * `flash.action_failed` (see `VenueDocumentsController` for why not `#[IsCsrfTokenValid]`).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}/bewertungen', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueReviewsController extends AbstractController
{
    public const string CSRF_ID = 'cms_review';

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenueReviewsQuery $reviews,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('', name: 'admin_locations_reviews', methods: ['GET'])]
    public function index(int $number): Response
    {
        $venue = $this->venue($number);

        return $this->render('admin/cms/venue/reviews.html.twig', [
            'venue' => $venue,
            'reviews' => $this->reviews->forVenue($number) ?? [],
            'rating_url' => $this->generateUrl('admin_locations_edit', ['number' => $number]).'#'.VenueCard::Rating->anchor(),
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    #[Route('/neu', name: 'admin_locations_reviews_new', methods: ['GET', 'POST'])]
    public function new(int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        $form = $this->createForm(ReviewType::class, ReviewData::new($this->locales->default()), [
            'action' => $this->generateUrl('admin_locations_reviews_new', ['number' => $number]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderForm($venue, $form, null);
        }
        if (!$form->isValid()) {
            return $this->renderForm($venue, $form, null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof ReviewData);
        try {
            $this->commandBus->dispatch($data->toAddCommand($number));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->addFlash('success', CmsFlash::message('reviews.flash.added', ['name' => trim((string) $data->firstName)]));

        return $this->backToList($number);
    }

    #[Route('/{id}', name: 'admin_locations_reviews_edit', methods: ['GET', 'POST'])]
    public function edit(int $number, string $id, Request $request): Response
    {
        $venue = $this->venue($number);
        $review = $this->reviews->review($number, $id) ?? throw $this->createNotFoundException();
        $form = $this->createForm(ReviewType::class, ReviewData::from($review), [
            'edit' => true,
            'action' => $this->generateUrl('admin_locations_reviews_edit', ['number' => $number, 'id' => $id]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderForm($venue, $form, $review);
        }
        if (!$form->isValid()) {
            return $this->renderForm($venue, $form, $review, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof ReviewData);
        if (self::unchanged($review, $data)) {
            $this->addFlash('success', CmsFlash::message('reviews.flash.unchanged', ['name' => $review->firstName]));

            return $this->backToList($number);
        }

        try {
            $this->commandBus->dispatch($data->toChangeCommand($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            if ([] === $failure->getWrappedExceptions(StaleEdit::class)) {
                throw $failure;
            }
            $form->addError(new FormError($this->translator->trans('error.stale', [], 'cms')));

            return $this->renderForm($venue, $form, $review, Response::HTTP_CONFLICT, stale: true);
        }
        $this->addFlash('success', CmsFlash::message('reviews.flash.saved', ['name' => trim((string) $data->firstName)]));

        return $this->backToList($number);
    }

    #[Route('/{id}/loeschen', name: 'admin_locations_reviews_remove', methods: ['POST'])]
    public function remove(int $number, string $id, Request $request): Response
    {
        $this->venue($number);
        $review = $this->reviews->review($number, $id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.action_failed');

            return $this->backToList($number);
        }

        try {
            $this->commandBus->dispatch(new RemoveVenueReview($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->addFlash('success', CmsFlash::message('reviews.flash.removed', ['name' => $review->firstName]));

        return $this->backToList($number);
    }

    private function venue(int $number): VenueHeader
    {
        return $this->headers->find($number) ?? throw $this->createNotFoundException();
    }

    private function rethrowNotFound(HandlerFailedException $failure): void
    {
        if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * The input as the handler compares it (trimmed), so an idle save dispatches nothing.
     */
    private static function unchanged(ManagedReview $review, ReviewData $data): bool
    {
        return trim((string) $data->firstName) === $review->firstName
            && $data->role === $review->role
            && $data->month === $review->month
            && trim((string) $data->text) === $review->text;
    }

    private function backToList(int $number): RedirectResponse
    {
        return $this->redirectToRoute('admin_locations_reviews', ['number' => $number]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderForm(VenueHeader $venue, FormInterface $form, ?ManagedReview $review, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        return $this->render('admin/cms/venue/review_form.html.twig', [
            'venue' => $venue,
            'review' => $review,
            'form' => $form->createView(),
            'stale' => $stale,
        ], new Response(status: $status));
    }
}
