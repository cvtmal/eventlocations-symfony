<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\CompanyListFilter;
use App\Crm\ValueObject\CompanyStage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The company list's filter: a GET form in the query string (`createNamed('')`), as #24's venue list.
 * `sort`, `dir` and `page` are read beside it (`allow_extra_fields`). P10: selects, no tabs.
 */
final class CompanyFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, ['label' => 'list.filter.search', 'required' => false])
            ->add('stage', EnumType::class, ['class' => CompanyStage::class, 'label' => 'list.filter.stage', 'required' => false, 'placeholder' => 'list.filter.all_stages'])
            ->add('owner', ChoiceType::class, [
                'label' => 'list.filter.owner',
                'required' => false,
                'placeholder' => 'list.filter.all_owners',
                ...OwnerChoices::options($options['owners'], withNone: true),
            ])
            ->add('venues', ChoiceType::class, [
                'label' => 'list.filter.venues',
                'required' => false,
                'placeholder' => 'list.filter.all_venues',
                'choices' => [
                    'list.filter.venues_with' => CompanyListFilter::VENUES_WITH,
                    'list.filter.venues_without' => CompanyListFilter::VENUES_WITHOUT,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompanyFilterData::class,
            'translation_domain' => 'crm',
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('owners');
        $resolver->setAllowedTypes('owners', 'array');
    }
}
