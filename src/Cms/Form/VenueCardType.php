<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What every venue editor card shares: the stale token field, the `cms` labels and the `venue` option, the
 * `VenueEdit` the card was loaded from, for the fields that depend on it (the locales with a row, whether a
 * region is shown). Build a card through `VenueEditorForms`, which sets `venue` and `action`.
 */
abstract class VenueCardType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('loadedAt', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'cms']);
        $resolver->setRequired('venue');
        $resolver->setAllowedTypes('venue', VenueEdit::class);
    }
}
