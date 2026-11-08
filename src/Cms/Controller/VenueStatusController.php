<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\Twig\VenueActionsExtension;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\CheckVenueFacts;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Event\VenueEvents;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publish, unpublish and delete (CMS-3), posted by the record header's forms (`cms_venue_action_form()`), so
 * they come from the editor and from #25's tabs alike, and go back to the editor (delete: to the list). A venue
 * already in the asked state dispatches nothing, so a double click leaves no second audit row. A refusal is a
 * flash: a refused publish names what is missing, in the checklist's words. The first publish of a venue made with
 * "Neue Location" or the portal's wizard fires `NewVenuePublished` after the commit (Q20).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueStatusController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly FormFactoryInterface $forms,
        private readonly MessageBusInterface $commandBus,
        private readonly VenueEvents $events,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/publizieren', name: 'admin_locations_publish', methods: ['POST'])]
    public function publish(int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        if ($this->confirmed('publish', $number, $request)) {
            if (VenueStatus::Published === $venue->status) {
                $this->addFlash('success', CmsFlash::message('publish.flash.already_published', ['name' => $venue->name]));
            } elseif (null !== $published = $this->tryDispatch(new PublishVenue($number))) {
                if (true === $published->last(HandledStamp::class)?->getResult()) {
                    $this->events->newVenuePublished($venue->id);
                }
                $this->addFlash('success', CmsFlash::message('publish.flash.published', ['name' => $venue->name]));
            }
        }

        return $this->redirectToRoute('admin_locations_edit', ['number' => $number]);
    }

    #[Route('/unpublizieren', name: 'admin_locations_unpublish', methods: ['POST'])]
    public function unpublish(int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        if ($this->confirmed('unpublish', $number, $request)) {
            if (VenueStatus::Published !== $venue->status) {
                $this->addFlash('success', CmsFlash::message('publish.flash.already_unpublished', ['name' => $venue->name]));
            } elseif (null !== $this->tryDispatch(new UnpublishVenue($number))) {
                $this->addFlash('success', CmsFlash::message('publish.flash.unpublished', ['name' => $venue->name]));
            }
        }

        return $this->redirectToRoute('admin_locations_edit', ['number' => $number]);
    }

    /**
     * G12: staff confirm that the entry's facts are checked (with a description, it is then indexed) or withdraw it.
     */
    #[Route('/fakten-geprueft', name: 'admin_locations_check_facts', methods: ['POST'])]
    public function checkFacts(int $number, Request $request): Response
    {
        return $this->factsChecked(true, $number, $request);
    }

    #[Route('/fakten-offen', name: 'admin_locations_uncheck_facts', methods: ['POST'])]
    public function uncheckFacts(int $number, Request $request): Response
    {
        return $this->factsChecked(false, $number, $request);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/loeschen', name: 'admin_locations_delete', methods: ['POST'])]
    public function delete(int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        if ($this->confirmed('delete', $number, $request) && null !== $this->tryDispatch(new DeleteVenue($number, $venue->name, $venue->contentKey))) {
            $this->addFlash('success', CmsFlash::message('delete.flash', ['name' => $venue->name]));

            return $this->redirectToRoute('admin_locations');
        }

        return $this->redirectToRoute('admin_locations_edit', ['number' => $number]);
    }

    private function factsChecked(bool $checked, int $number, Request $request): Response
    {
        $venue = $this->venue($number);
        // Already in the asked state (a double click, another tab): no dispatch, so no second audit row.
        if ($this->confirmed($checked ? 'check_facts' : 'uncheck_facts', $number, $request)
            && ($venue->factsChecked === $checked || null !== $this->tryDispatch(new CheckVenueFacts($number, $checked)))) {
            $this->addFlash('success', CmsFlash::message($checked ? 'indexing.flash.checked' : 'indexing.flash.unchecked', ['name' => $venue->name]));
        }

        return $this->redirect($this->generateUrl('admin_locations_edit', ['number' => $number]).'#venue-indexing');
    }

    private function venue(int $number): VenueHeader
    {
        return $this->headers->find($number) ?? throw $this->createNotFoundException();
    }

    /**
     * The header's form with its CSRF token; a missing or wrong token changes nothing.
     */
    private function confirmed(string $action, int $number, Request $request): bool
    {
        $form = $this->forms->createNamed($action, ConfirmActionType::class, null, [
            'action' => $this->generateUrl(VenueActionsExtension::ROUTES[$action], ['number' => $number]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return true;
        }
        $this->addFlash('error', 'flash.action_failed');

        return false;
    }

    /**
     * @return Envelope|null null when a business rule refused the command; the reason is then flashed
     */
    private function tryDispatch(object $command): ?Envelope
    {
        try {
            return $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw $this->createNotFoundException();
            }
            $violation = VenueRuleViolation::unwrap($failure);
            if ([] !== $violation->missing) {
                $labels = array_map(fn (string $code): string => $this->translator->trans('editor.checklist.item.'.$code, [], 'cms'), $violation->missing);
                $this->addFlash('error', CmsFlash::message('publish.error.missing', ['missing' => implode(', ', $labels)]));
            } else {
                $this->addFlash('error', CmsFlash::message($violation->translationKey(), $violation->parameters));
            }

            return null;
        }
    }
}
