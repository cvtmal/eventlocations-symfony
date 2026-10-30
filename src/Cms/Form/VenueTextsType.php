<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Translation\EnabledLocale;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One `VenueLocaleTextsType` per enabled locale under `texts[<locale>]`, as named children rather than a
 * `CollectionType`, because only the default locale's summary is required and a collection gives every
 * entry the same options.
 */
final class VenueTextsType extends VenueCardType
{
    public function __construct(
        private readonly EnabledLocale $locales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $texts = $builder->create('texts', FormType::class, ['label' => false]);
        foreach ($this->locales->all() as $locale) {
            $texts->add($locale, VenueLocaleTextsType::class, [
                'label' => false,
                'summary_required' => $locale === $this->locales->default(),
            ]);
        }
        $builder->add($texts);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueTextsData::class);
    }
}
