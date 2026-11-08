<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use App\Inquiries\ReadModel\InquiryVocabularyQuery;
use App\Venues\ValueObject\Term;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "{Name} kontaktieren" form (DP-13), in DP-13's order, plus the two spam fields of IQ-3.
 * "Anlass" and "Catering" offer the venue vocabulary (`InquiryVocabularyQuery`).
 * Labels are in `inquiries/_form.html.twig`. `website` is the honeypot; `started` always gets a
 * fresh `FillTimeToken`, so a re-render after an error can be sent again.
 */
final class InquiryType extends AbstractType
{
    public function __construct(
        private readonly FillTimeToken $fillTime,
        private readonly InquiryVocabularyQuery $vocabulary,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('eventType', ChoiceType::class, self::termChoices($this->vocabulary->eventTypes()) + [
                'placeholder' => 'form.placeholder.choose',
                'invalid_message' => 'inquiry.event_type.required',
            ])
            ->add('guests', IntegerType::class, [
                'attr' => ['min' => 1, 'max' => 5000, 'inputmode' => 'numeric'],
                'invalid_message' => 'inquiry.guests.invalid',
            ])
            ->add('eventDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'inquiry.event_date.invalid',
            ])
            ->add('dateFlexible', CheckboxType::class, ['required' => false])
            ->add('startTime', TimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'inquiry.start_time.invalid',
            ])
            ->add('cateringWish', ChoiceType::class, self::termChoices($this->vocabulary->cateringWishes()) + [
                'required' => false,
                'placeholder' => 'form.placeholder.catering_open',
                'invalid_message' => 'inquiry.catering_wish.invalid',
            ])
            ->add('budgetChf', IntegerType::class, [
                'required' => false,
                'attr' => ['min' => 1, 'max' => 1_000_000, 'inputmode' => 'numeric'],
                'invalid_message' => 'inquiry.budget.invalid',
            ])
            ->add('message', TextareaType::class, ['attr' => ['rows' => 7]])
            ->add('firstName', TextType::class, ['attr' => ['autocomplete' => 'given-name']])
            ->add('lastName', TextType::class, ['attr' => ['autocomplete' => 'family-name']])
            ->add('company', TextType::class, ['required' => false, 'attr' => ['autocomplete' => 'organization']])
            ->add('email', EmailType::class, ['attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'tabindex' => -1],
            ])
            ->add('started', HiddenType::class, ['mapped' => false]);
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view['started']->vars['value'] = $this->fillTime->issue();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InquiryData::class,
            'translation_domain' => 'inquiries',
        ]);
    }

    /**
     * The codes are the values, the terms their labels (they translate themselves in the `venues` catalogue).
     *
     * @param list<Term> $terms
     *
     * @return array{choices: array<string, string>, choice_label: \Closure(string): Term}
     */
    private static function termChoices(array $terms): array
    {
        $byCode = [];
        foreach ($terms as $term) {
            $byCode[$term->code] = $term;
        }

        return [
            'choices' => array_combine(array_keys($byCode), array_keys($byCode)),
            'choice_label' => static fn (string $code): Term => $byCode[$code],
        ];
    }

    public function getBlockPrefix(): string
    {
        return 'inquiry';
    }
}
