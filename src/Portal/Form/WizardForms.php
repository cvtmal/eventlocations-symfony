<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Cms\Form\VenueContactData;
use App\Cms\ReadModel\VenueEdit;
use App\Portal\Security\PortalIdentity;
use App\Portal\ValueObject\WizardStep;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds a wizard step's card form. Beside `LocationForms`, because a step posts to its own URL, and the contact
 * step requires the inquiry address and starts with the account's. `VenueCardSaver` diffs against a fresh
 * `from($edit)`, so a kept prefill counts as a change and is stored.
 */
final readonly class WizardForms
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function form(WizardStep $step, VenueEdit $edit, PortalIdentity $identity): FormInterface
    {
        $card = $step->card() ?? throw new \LogicException(\sprintf('The step "%s" has no card.', $step->value));
        $data = $card->dataClass()::from($edit);
        $type = $card->formType();
        if ($data instanceof VenueContactData) {
            $data->inquiryEmail ??= $identity->email();
            $type = WizardContactType::class;
        }

        return $this->forms->createNamed($card->formName(), $type, $data, [
            'action' => $this->urls->generate('portal_location_setup_step', ['number' => $edit->number(), 'step' => $step->value]),
            'venue' => $edit,
        ]);
    }
}
