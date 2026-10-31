<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\ContactKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The contact list's filter: a GET form in the query string (`createNamed('')`).
 */
final class ContactFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, ['label' => 'list.filter.search', 'required' => false])
            ->add('kind', EnumType::class, ['class' => ContactKind::class, 'label' => 'list.filter.kind', 'required' => false, 'placeholder' => 'list.filter.all_kinds'])
            ->add('owner', ChoiceType::class, [
                'label' => 'list.filter.owner',
                'required' => false,
                'placeholder' => 'list.filter.all_owners',
                ...OwnerChoices::options($options['owners'], withNone: true),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactFilterData::class,
            'translation_domain' => 'crm',
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('owners');
        $resolver->setAllowedTypes('owners', 'array');
    }
}
