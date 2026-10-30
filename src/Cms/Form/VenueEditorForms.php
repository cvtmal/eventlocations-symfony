<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Cms\ValueObject\VenueCard;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the editor's card forms: named (`venue_basics` …), so eight forms share one page and each POSTs
 * to its own route, and started from `VenueCardData::from()`.
 */
final class VenueEditorForms
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function form(VenueCard $card, VenueEdit $edit): FormInterface
    {
        $options = [
            'action' => $this->urls->generate($card->route(), ['number' => $edit->number()]),
            'venue' => $edit,
        ];
        if (VenueCard::Address === $card) {
            $options['town_lookup_url'] = $this->urls->generate('admin_api_cms_towns');
        }

        return $this->forms->createNamed($card->formName(), $card->formType(), $card->dataClass()::from($edit), $options);
    }

    /**
     * Every card's view, keyed by the card's value (`basics` …); `$bound` is the card a failed POST
     * re-renders with its errors and submitted values, the others start fresh.
     *
     * @return array<string, FormView>
     */
    public function views(VenueEdit $edit, ?VenueCard $bound = null, ?FormInterface $boundForm = null): array
    {
        $views = [];
        foreach (VenueCard::cases() as $card) {
            $form = $card === $bound && null !== $boundForm ? $boundForm : $this->form($card, $edit);
            $views[$card->value] = $form->createView();
        }

        return $views;
    }
}
