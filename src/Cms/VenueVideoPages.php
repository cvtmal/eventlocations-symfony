<?php

declare(strict_types=1);

namespace App\Cms;

use App\Cms\Form\VideoUrlData;
use App\Cms\Form\VideoUrlType;
use App\Cms\ReadModel\ManagedVideo;
use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueVideosQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VideoPagesSetup;
use App\Venues\Command\RemoveVenueVideo;
use App\Venues\Command\ReorderVenueVideos;
use App\Venues\EntitlementResolver;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Psr\Clock\ClockInterface;
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
 * The Videos pages (G15), shared by staff's `Cms\Controller\VenueVideosController` and the manager's
 * `Portal\Controller\LocationVideosController`, which keep access and routes; `VideoPagesSetup` names the host's
 * routes, template and extra context. A server page without JavaScript, built like the Dokumente tab: the list
 * reorders by move buttons (one POST route per direction) and removes through a confirm dialog; the card adds a video by
 * its YouTube or Vimeo link. Every write is POST → redirect → GET with a flash; invalid input and a refused link are a
 * 422 with the value kept. A video over the plan's limit is the handler's 422 (`TierLimits`, F12); every write purges
 * the venue's HTTP cache after the commit (its Venues handler). The list marks the videos the plan hides.
 *
 * The move and remove forms carry the host's token id. A missing or wrong one changes nothing and flashes
 * `flash.action_failed`, as the Dokumente tab does.
 */
final readonly class VenueVideoPages
{
    public function __construct(
        private VenueHeaderQuery $headers,
        private VenueVideosQuery $videos,
        private EntitlementResolver $entitlements,
        private ClockInterface $clock,
        private MessageBusInterface $commandBus,
        private TranslatorInterface $translator,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
        private RequestStack $requests,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function index(VideoPagesSetup $setup, int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        $form = $this->forms->create(VideoUrlType::class, new VideoUrlData(), [
            'action' => $this->urls->generate($setup->route, ['number' => $number]),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->renderList($setup, $venue, $form);
        }
        if (!$form->isValid()) {
            return $this->renderList($setup, $venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $data = $form->getData();
        \assert($data instanceof VideoUrlData);
        try {
            $this->commandBus->dispatch($data->toCommand($number));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            $violation = VenueRuleViolation::unwrap($failure);
            $form->get('url')->addError(new FormError($this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')));

            return $this->renderList($setup, $venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->flash('success', CmsFlash::message('videos.flash.added'));

        return $this->backToList($setup, $number);
    }

    public function remove(VideoPagesSetup $setup, int $number, string $id, Request $request): Response
    {
        $this->venue($number);
        $video = $this->find($number, $id);
        if (!$this->tokenIsValid($setup, $request)) {
            return $this->backToList($setup, $number);
        }
        try {
            $this->commandBus->dispatch(new RemoveVenueVideo($number, $video->id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->flash('success', CmsFlash::message('videos.flash.removed', ['position' => $video->position]));

        return $this->backToList($setup, $number);
    }

    /**
     * Swaps the video with its neighbour and sends the whole order; a move past either end is a no-op, as the list
     * renders those buttons `aria-disabled`.
     */
    public function move(VideoPagesSetup $setup, int $number, string $id, int $step, Request $request): Response
    {
        $this->venue($number);
        $videos = $this->videos->forVenue($number) ?? throw new NotFoundHttpException();
        $ids = array_map(static fn (ManagedVideo $video): string => $video->id, $videos);
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
            $this->commandBus->dispatch(new ReorderVenueVideos($number, array_values($ids)));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);
            if ([] === $failure->getWrappedExceptions(StaleEdit::class)) {
                throw $failure;
            }
            $this->flash('error', CmsFlash::message('error.stale'));

            return $this->backToList($setup, $number);
        }
        $this->flash('success', CmsFlash::message('videos.flash.moved', ['from' => $index + 1, 'position' => $target + 1]));

        return new RedirectResponse($this->urls->generate($setup->route, ['number' => $number, '_fragment' => 'venue-videos']));
    }

    private function venue(int $number): VenueHeader
    {
        return $this->headers->find($number) ?? throw new NotFoundHttpException();
    }

    private function find(int $number, string $id): ManagedVideo
    {
        foreach ($this->videos->forVenue($number) ?? [] as $video) {
            if ($video->id === $id) {
                return $video;
            }
        }

        throw new NotFoundHttpException();
    }

    private function tokenIsValid(VideoPagesSetup $setup, Request $request): bool
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

    private function backToList(VideoPagesSetup $setup, int $number): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate($setup->route, ['number' => $number]));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderList(VideoPagesSetup $setup, VenueHeader $venue, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $entitlements = $this->entitlements->forVenue($venue->id, $this->clock->now());

        return new Response($this->twig->render($setup->template, ($setup->context)($venue) + [
            'venue' => $venue,
            'videos' => $this->videos->forVenue($venue->number) ?? [],
            'videos_max' => $entitlements->videosMax,
            'tier_name' => $entitlements->tierName,
            'form' => $form->createView(),
            'csrf_id' => $setup->csrfId,
            'videos_route' => $setup->route,
        ]), $status);
    }
}
