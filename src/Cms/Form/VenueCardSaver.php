<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Cms\ValueObject\VenueCardSaveOutcome;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Saves one handled venue card form (D11): input equal to what was loaded dispatches nothing, so an idle
 * click leaves no audit row and no `cms_edited_at`. A stale save or a handler's refusal lands on the form,
 * which the caller re-renders by the outcome.
 */
final readonly class VenueCardSaver
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param FormInterface<mixed> $form the handled form
     *
     * @throws NotFoundHttpException when the venue went away during the save
     */
    public function save(FormInterface $form, int $number, VenueEdit $edit): VenueCardSaveOutcome
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return VenueCardSaveOutcome::Invalid;
        }

        $data = $form->getData();
        \assert($data instanceof VenueCardData);
        $changed = ChangedFields::between($data::from($edit), $data);

        if ([] === $changed && !($data instanceof VenueAddressData && $data->useTownCentre)) {
            return VenueCardSaveOutcome::Unchanged;
        }

        try {
            $this->commandBus->dispatch($data->toCommand($number, $edit, $changed));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw new NotFoundHttpException('Not Found');
            }
            if ([] !== $failure->getWrappedExceptions(StaleEdit::class)) {
                $form->addError(new FormError($this->translator->trans('error.stale', [], 'cms')));

                return VenueCardSaveOutcome::Stale;
            }

            $violation = VenueRuleViolation::unwrap($failure);
            $target = null !== $violation->field && $form->has($violation->field) ? $form->get($violation->field) : $form;
            $target->addError(new FormError($this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')));

            return VenueCardSaveOutcome::Invalid;
        }

        return VenueCardSaveOutcome::Saved;
    }
}
