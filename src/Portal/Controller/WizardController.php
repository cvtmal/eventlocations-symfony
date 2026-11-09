<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\Form\PhotoAltData;
use App\Cms\Form\VenueCardSaver;
use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueEditQuery;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Cms\ValueObject\VenueCardSaveOutcome;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Cms\VenuePhotoUpload;
use App\Media\Exception\UnreadableImage;
use App\Portal\Command\SubmitLocation;
use App\Portal\Event\LocationSubmitted;
use App\Portal\Event\PortalEvents;
use App\Portal\Form\SubmitLocationData;
use App\Portal\Form\SubmitLocationType;
use App\Portal\Form\WizardForms;
use App\Portal\Form\WizardPhotoType;
use App\Portal\Mailer\PortalMailer;
use App\Portal\ManagerUploadGrant;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\PortalProfileQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\ReadModel\SubmissionState;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\WizardStep;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ReadModel\VenuePublishGaps;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The wizard's short pages (#45, POR-11). No step is stored: the bare URL derives where to go on from what the
 * location lacks, and a save always goes to the next step in fixed order, or a filled step would be jumped over
 * and `veranstaltung` would never appear. Any step is open to a manager with access; a published location has left.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[Route('/konto/locations/{number}/einrichten', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class WizardController extends AbstractController
{
    public function __construct(
        private readonly VenueEditQuery $edits,
        private readonly VenuePublishGaps $gaps,
        private readonly WizardForms $forms,
        private readonly VenueCardSaver $saver,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
        private readonly VenuePhotosQuery $photos,
        private readonly VenuePhotoUpload $upload,
        private readonly SubmissionQuery $submissions,
        private readonly PortalProfileQuery $profiles,
        private readonly PortalMailer $mailer,
        private readonly PortalEvents $events,
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactoryInterface $portalUploadLimiter,
        private readonly ManagerUploadGrant $grants,
        private readonly LocationPlanQuery $plans,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'portal_location_setup', methods: ['GET'])]
    public function resume(int $number): Response
    {
        $edit = $this->venue($number);
        if ($edit->header->isPublished) {
            return $this->redirectToRoute('portal_location', ['number' => $number]);
        }

        return $this->toStep(WizardStep::derive($this->gaps->missing($edit->header->id)), $number, Response::HTTP_FOUND);
    }

    #[Route('/{step}', name: 'portal_location_setup_step', requirements: ['step' => WizardStep::REQUIREMENT], methods: ['GET', 'POST'])]
    public function step(int $number, WizardStep $step, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        $edit = $this->venue($number);
        if ($edit->header->isPublished) {
            return $this->redirectToRoute('portal_location', ['number' => $number]);
        }

        return match ($step) {
            WizardStep::Photos => $this->photos($step, $edit, $request, $identity),
            WizardStep::Check => $this->check($step, $edit, $request, $identity),
            default => $this->card($step, $edit, $request, $identity),
        };
    }

    private function card(WizardStep $step, VenueEdit $edit, Request $request, PortalIdentity $identity): Response
    {
        $form = $this->forms->form($step, $edit, $identity);
        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->renderCard($step, $edit, $form);
        }

        $form->handleRequest($request);

        return match ($this->saver->save($form, $edit->number(), $edit)) {
            VenueCardSaveOutcome::Saved, VenueCardSaveOutcome::Unchanged => $this->toStep($step->next(), $edit->number(), Response::HTTP_SEE_OTHER),
            VenueCardSaveOutcome::Invalid => $this->renderCard($step, $edit, $form, Response::HTTP_UNPROCESSABLE_ENTITY),
            VenueCardSaveOutcome::Stale => $this->renderCard($step, $edit, $form, Response::HTTP_CONFLICT, stale: true),
        };
    }

    /**
     * The form for a browser without JavaScript; the island talks to `portal_api_photos_*`. The limiter comes first, so
     * every attempt counts, as in the API controller.
     */
    private function photos(WizardStep $step, VenueEdit $edit, Request $request, PortalIdentity $identity): Response
    {
        $form = $this->createForm(WizardPhotoType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->renderPhotos($edit, $form);
        }

        if (!$this->portalUploadLimiter->create($identity->id())->consume(1)->isAccepted()) {
            return $this->refusePhoto($edit, $form, $this->translator->trans('portal.photo.rate_limited', [], 'validators'));
        }
        if (!$form->isValid()) {
            return $this->renderPhotos($edit, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $form->get('file')->getData();
        \assert($file instanceof UploadedFile);
        $extension = AddVenuePhoto::EXTENSIONS[(string) $file->getMimeType()] ?? null;
        if (null === $extension) {
            return $this->refusePhoto($edit, $form, $this->translator->trans('cms.photo.type', [], 'validators'));
        }
        if ($this->upload->isTooTall($file->getPathname())) {
            return $this->refusePhoto($edit, $form, $this->translator->trans('cms.photo.too_tall', ['ratio' => $this->upload->maxPortraitRatio()], 'validators'));
        }
        if ($this->upload->hasTooManyScans($file->getPathname(), (string) $file->getMimeType())) {
            return $this->refusePhoto($edit, $form, $this->translator->trans('cms.photo.unreadable', [], 'validators'));
        }

        try {
            $this->commandBus->dispatch(new AddVenuePhoto($edit->number(), null, $file->getPathname(), $extension, $this->grants->forUpload($identity->id(), $edit->number())));
        } catch (HandlerFailedException $failure) {
            return $this->refusePhoto($edit, $form, $this->photoFailure($failure));
        }

        return $this->toStep($step, $edit->number(), Response::HTTP_SEE_OTHER);
    }

    private function photoFailure(HandlerFailedException $failure): string
    {
        if ([] !== $failure->getWrappedExceptions(UnreadableImage::class)) {
            return $this->translator->trans('cms.photo.unreadable', [], 'validators');
        }

        $violation = VenueRuleViolation::unwrap($failure);

        return $this->translator->trans($violation->translationKey(), $violation->parameters, 'cms');
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function refusePhoto(VenueEdit $edit, FormInterface $form, string $message): Response
    {
        $form->get('file')->addError(new FormError($message));

        return $this->renderPhotos($edit, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A missing inquiry address is a publish gap like any other (D60): the check page names it with a link to its step.
     */
    private function check(WizardStep $step, VenueEdit $edit, Request $request, PortalIdentity $identity): Response
    {
        $state = $this->submissions->find($edit->header->id);

        $form = $this->createForm(SubmitLocationType::class, new SubmitLocationData());
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->renderCheck($edit, $state, $form);
        }
        if (!$form->isValid()) {
            return $this->renderCheck($edit, $state, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof SubmitLocationData);
        try {
            $started = $this->commandBus->dispatch(new SubmitLocation($identity->id(), $edit->number(), $data->derivatives))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            $form->addError(new FormError($this->refusal(VenueRuleViolation::unwrap($failure))));

            return $this->renderCheck($edit, $state, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (true === $started) {
            $this->announce($edit, $identity);
        }

        return $this->toStep($step, $edit->number(), Response::HTTP_SEE_OTHER);
    }

    private function refusal(VenueRuleViolation $violation): string
    {
        $labels = array_map(fn (string $code): string => $this->translator->trans('editor.checklist.item.'.$code, [], 'cms'), $violation->missing);

        return $this->translator->trans('onboarding.check.refused', ['missing' => implode(', ', $labels)], 'portal');
    }

    /**
     * After the commit, so a mail cannot undo the submit. `PortalMailer` swallows a failed send.
     */
    private function announce(VenueEdit $edit, PortalIdentity $identity): void
    {
        $profile = $this->profiles->find($identity->id()) ?? throw $this->createNotFoundException();
        $name = $edit->header->name;

        // In `finally` so that every submit is published, also on an unexpected mail error.
        try {
            $this->mailer->sendLocationSubmitted($profile->email, $profile->firstName, $name);
            $this->mailer->sendLocationNotice($name, $profile->firstName.' '.$profile->lastName, $profile->email);
        } finally {
            $this->events->locationSubmitted(new LocationSubmitted($edit->header->id, $name, $profile->email, $profile->firstName, $profile->lastName, $profile->phone));
        }
    }

    private function venue(int $number): VenueEdit
    {
        return $this->edits->find($number) ?? throw $this->createNotFoundException();
    }

    private function toStep(WizardStep $step, int $number, int $status): RedirectResponse
    {
        return $this->redirectToRoute('portal_location_setup_step', ['number' => $number, 'step' => $step->value], $status);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderCard(WizardStep $step, VenueEdit $edit, FormInterface $form, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        $locales = [];
        foreach ($this->locales->all() as $locale) {
            $locales[] = ['code' => $locale, 'label' => $this->translator->trans('editor.locale.'.$locale, [], 'cms')];
        }

        return $this->render('portal/wizard/card.html.twig', [
            'form' => $form,
            'step' => $step,
            'card' => $step->card(),
            'venue' => $edit->header,
            'stale' => $stale,
            'locales' => $locales,
            // The event step shows the plan's event-type limit (D40, F11).
            'plan' => WizardStep::Event === $step ? $this->plans->forVenue($edit->header->id, $this->clock->now()) : null,
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderPhotos(VenueEdit $edit, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $view = $this->photos->forVenue($edit->number(), $this->locales->default()) ?? throw $this->createNotFoundException();

        return $this->render('portal/wizard/photos.html.twig', [
            'form' => $form,
            'venue' => $edit->header,
            'photos' => $view->photosArray(),
            'rooms' => [],
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'id_placeholder' => VenueMediaApi::ID_PLACEHOLDER,
            'max_bytes' => VenuePhotoUpload::MAX_BYTES,
            'accept' => implode(',', array_keys(AddVenuePhoto::EXTENSIONS)),
            'alt_max' => PhotoAltData::ALT_MAX,
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderCheck(VenueEdit $edit, ?SubmissionState $state, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $gaps = [];
        foreach ($this->gaps->missing($edit->header->id) as $code) {
            $gaps[] = ['code' => $code, 'step' => WizardStep::forGap($code)->value];
        }

        return $this->render('portal/wizard/check.html.twig', [
            'form' => $form,
            'venue' => $edit->header,
            'state' => $state,
            'gaps' => $gaps,
        ], new Response(status: $status));
    }
}
