<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The decision of a complaint (G2): an optional note and one button per decision, so a click names it.
 */
final class ModerationDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('note', TextareaType::class, [
                'label' => 'moderation.case.decide.note',
                'required' => false,
                'attr' => ['rows' => 4, 'data-counter-max' => ModerationDecisionData::NOTE_MAX],
            ])
            ->add('remove', SubmitType::class, ['label' => 'moderation.case.decide.remove', 'attr' => ['class' => 'btn btn--danger']])
            ->add('keep', SubmitType::class, ['label' => 'moderation.case.decide.keep', 'attr' => ['class' => 'btn']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ModerationDecisionData::class, 'translation_domain' => 'cms']);
    }
}
