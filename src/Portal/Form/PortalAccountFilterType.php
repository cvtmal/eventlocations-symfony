<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The staff list's search: a GET form in the query string (`createNamed('')`), as the CRM lists. `page` is read
 * beside it (`allow_extra_fields`). `AdminTextConstraintExtension` adds `CleanText`, since the route is under /admin.
 */
final class PortalAccountFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('q', SearchType::class, ['label' => 'accounts.search.label', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PortalAccountFilterData::class,
            'translation_domain' => 'portal',
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }
}
