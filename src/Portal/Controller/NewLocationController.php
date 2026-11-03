<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueListFilter;
use App\Cms\ReadModel\VenueListQuery;
use App\Cms\ReadModel\VenueListRow;
use App\Portal\Exception\TooManyUnfinishedLocations;
use App\Portal\Form\NewLocationData;
use App\Portal\Form\NewLocationType;
use App\Portal\Security\PortalIdentity;
use App\Portal\ValueObject\WizardStep;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Step 1 of the wizard (#45), the only one before a number exists. A name that matches a published location shows
 * the matches first and writes nothing; `confirmed`, a button value outside the form, creates anyway.
 */
#[IsGranted('ROLE_PORTAL_USER')]
final class NewLocationController extends AbstractController
{
    public const int MATCH_LIMIT = 5;

    public function __construct(
        private readonly VenueListQuery $venues,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/konto/locations/neu', name: 'portal_locations_new', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        $data = new NewLocationData();
        $form = $this->createForm(NewLocationType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->page($form);
        }
        if (!$form->isValid()) {
            return $this->page($form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$request->request->has('confirmed')) {
            $matches = $this->venues->quickSearch((string) $data->name, self::MATCH_LIMIT, VenueListFilter::STATUS_PUBLISHED);
            if ([] !== $matches) {
                return $this->page($form, $matches);
            }
        }

        try {
            $number = $this->commandBus->dispatch($data->toCommand($identity->id()))->last(HandledStamp::class)?->getResult();
            \assert(\is_int($number));

            return $this->redirectToRoute('portal_location_setup_step', ['number' => $number, 'step' => WizardStep::Texts->value], Response::HTTP_SEE_OTHER);
        } catch (HandlerFailedException $failure) {
            $ambiguous = $this->addFailure($form, $failure);

            return $this->page($form, status: Response::HTTP_UNPROCESSABLE_ENTITY, askCanton: $ambiguous);
        }
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return bool whether the town is one that several cantons share
     */
    private function addFailure(FormInterface $form, HandlerFailedException $failure): bool
    {
        $previous = $failure->getPrevious();
        if ($previous instanceof TooManyUnfinishedLocations) {
            $form->addError(new FormError($this->translator->trans('onboarding.error.too_many', ['limit' => $previous->limit], 'portal')));

            return false;
        }

        $violation = VenueRuleViolation::unwrap($failure);
        $field = 'slug' === $violation->field ? 'name' : $violation->field;
        $target = null !== $field && $form->has($field) ? $form->get($field) : $form;
        $target->addError(new FormError($this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')));

        return VenueRuleViolation::ambiguousTown()->translationKey() === $violation->translationKey();
    }

    /**
     * The canton select shows once a town needs it, and stays while a canton is chosen, so a later error keeps it.
     *
     * @param FormInterface<mixed> $form
     * @param list<VenueListRow>   $matches
     */
    private function page(FormInterface $form, array $matches = [], int $status = Response::HTTP_OK, bool $askCanton = false): Response
    {
        $askCanton = $askCanton || null !== $form->get('canton')->getData();

        return $this->render('portal/wizard/new.html.twig', ['form' => $form, 'matches' => $matches, 'ask_canton' => $askCanton], new Response(status: $status));
    }
}
