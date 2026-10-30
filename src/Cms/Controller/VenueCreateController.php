<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Form\CreateVenueData;
use App\Cms\Form\CreateVenueType;
use App\Cms\ValueObject\CmsFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Location erfassen" (CMS-3): name, tier and capacity; the venue starts unpublished and the editor follows.
 */
#[IsGranted('ROLE_EDITOR')]
final class VenueCreateController extends AbstractController
{
    #[Route('/admin/locations/neu', name: 'admin_locations_new', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, MessageBusInterface $commandBus, TranslatorInterface $translator): Response
    {
        $data = new CreateVenueData();
        $form = $this->createForm(CreateVenueType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $number = $commandBus->dispatch($data->toCommand())->last(HandledStamp::class)?->getResult();
                \assert(\is_int($number));
                $this->addFlash('success', CmsFlash::message('create.flash', ['name' => (string) $data->name]));

                return $this->redirectToRoute('admin_locations_edit', ['number' => $number]);
            } catch (HandlerFailedException $failure) {
                $violation = VenueRuleViolation::unwrap($failure);
                // The only rule CreateVenue enforces is a name that gives a slug.
                $form->get('name')->addError(new FormError($translator->trans($violation->translationKey(), $violation->parameters, 'cms')));
            }
        }

        return $this->render('admin/cms/venue/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
