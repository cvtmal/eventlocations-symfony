<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Admin\Form\ComboboxType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "+ Verknüpfen" dialog of a company (CRM-1): a venue combobox on `admin_api_crm_venues`
 * (`venue_url`). It posts to `admin_crm_companies_link_venue` and comes back with a flash (PRG).
 */
final class LinkVenueType extends AbstractType
{
    public const string NAME = 'link_venue';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('venue', ComboboxType::class, ['label' => 'record.venues.link_field', 'url' => $options['venue_url'], 'attr' => ['autofocus' => true]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LinkVenueData::class, 'translation_domain' => 'crm']);
        $resolver->setRequired('venue_url');
        $resolver->setAllowedTypes('venue_url', 'string');
    }
}
