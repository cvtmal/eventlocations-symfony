<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Admin\Form\ComboboxType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The first step of "Deal erfassen" (N5): a GET form, so the choice is `?company=<id>` and "+ Deal" on a company can
 * link straight to the second step.
 */
final class DealPickCompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('company', ComboboxType::class, ['label' => 'create.deal.company', 'url' => $options['company_url']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'crm', 'method' => 'GET', 'csrf_protection' => false]);
        $resolver->setRequired('company_url');
        $resolver->setAllowedTypes('company_url', 'string');
    }
}
