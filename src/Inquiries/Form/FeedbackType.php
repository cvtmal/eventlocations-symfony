<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use App\Inquiries\ValueObject\FeedbackOutcome;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The feedback page's form (G16): two sets of radio buttons, no JS. CSRF is the site's stateless token (`submit`).
 */
final class FeedbackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('venueReplied', ChoiceType::class, [
                'choices' => ['feedback.answer.yes' => true, 'feedback.answer.no' => false],
                'expanded' => true,
                'placeholder' => false,
                'required' => true,
            ])
            ->add('outcome', EnumType::class, [
                'class' => FeedbackOutcome::class,
                'expanded' => true,
                'placeholder' => false,
                'required' => true,
                'choice_label' => static fn (FeedbackOutcome $outcome): string => 'feedback.outcome.'.$outcome->value,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeedbackData::class,
            'translation_domain' => 'inquiries',
        ]);
    }
}
