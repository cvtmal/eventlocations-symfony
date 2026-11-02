<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RejectSubmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, [
            'label' => 'onboarding.queue.field.reason',
            'attr' => ['rows' => 6, 'data-counter-max' => RejectSubmissionData::REASON_MAX],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RejectSubmissionData::class, 'translation_domain' => 'portal']);
    }
}
