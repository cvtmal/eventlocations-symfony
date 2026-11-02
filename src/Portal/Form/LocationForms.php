<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Admin\Form\ConfirmActionType;
use App\Cms\ReadModel\VenueEdit;
use App\Portal\ValueObject\LocationCard;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the manager's card forms, as `VenueEditorForms` builds staff's: named, so the cards share one page and
 * each POSTs to its own route, and started from `VenueCardData::from()`.
 */
final class LocationForms
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function form(LocationCard $card, VenueEdit $edit): FormInterface
    {
        $options = [
            'action' => $this->urls->generate($card->route(), ['number' => $edit->number()]),
            'venue' => $edit,
        ];
        if (LocationCard::Address === $card) {
            $options['town_lookup_url'] = $this->urls->generate('portal_api_towns');
        }

        return $this->forms->createNamed($card->formName(), $card->formType(), $card->dataClass()::from($edit), $options);
    }

    /**
     * The header's "Offline nehmen" button with its CSRF token. `cms_venue_action_form()` knows `admin_*` routes only.
     */
    public function offlineForm(int $number): FormInterface
    {
        return $this->forms->createNamed('offline', ConfirmActionType::class, null, [
            'action' => $this->urls->generate('portal_location_offline', ['number' => $number]),
        ]);
    }

    /**
     * Every card's view, keyed by the card's value; `$bound` is the card a failed POST re-renders with its errors
     * and submitted values, the others start fresh.
     *
     * @return array<string, FormView>
     */
    public function views(VenueEdit $edit, ?LocationCard $bound = null, ?FormInterface $boundForm = null): array
    {
        $views = [];
        foreach (LocationCard::cases() as $card) {
            $form = $card === $bound && null !== $boundForm ? $boundForm : $this->form($card, $edit);
            $views[$card->value] = $form->createView();
        }

        return $views;
    }
}
