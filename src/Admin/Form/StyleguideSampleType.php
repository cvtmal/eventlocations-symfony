<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One field of every kind for `/admin/_styleguide`. It is rendered, never handled.
 */
final class StyleguideSampleType extends AbstractType
{
    public const int SUMMARY_MAX = 200;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'ui.styleguide.form.name',
                'help' => 'ui.styleguide.form.name_help',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('street', TextType::class, ['label' => 'ui.styleguide.form.street', 'required' => false])
            ->add('summary', TextareaType::class, [
                'label' => 'ui.styleguide.form.summary',
                'required' => false,
                'attr' => ['data-counter-max' => self::SUMMARY_MAX],
                'constraints' => [new Assert\Length(max: self::SUMMARY_MAX)],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'ui.styleguide.form.amount',
                'currency' => 'CHF',
                'html5' => true,
                'required' => false,
            ])
            ->add('date', DateType::class, ['label' => 'ui.styleguide.form.date', 'widget' => 'single_text', 'required' => false])
            ->add('town', ComboboxType::class, [
                'label' => 'ui.styleguide.form.town',
                'help' => 'ui.styleguide.form.town_help',
                'free_text' => true,
                'required' => false,
                'static_options' => [
                    $this->option('option_meilen'),
                    $this->option('option_stafa'),
                    $this->option('option_kuesnacht'),
                ],
            ])
            ->add('owner', ComboboxType::class, [
                'label' => 'ui.styleguide.form.owner',
                'help' => 'ui.styleguide.form.owner_help',
                'required' => false,
                'static_options' => [
                    ['value' => '1', 'label' => $this->text('option_andrea'), 'detail' => null],
                    ['value' => '2', 'label' => $this->text('option_beat'), 'detail' => null],
                ],
            ])
            ->add('description', MarkdownType::class, ['label' => 'ui.styleguide.form.description', 'required' => false])
            ->add('daylight', ChoiceType::class, [
                'label' => 'ui.styleguide.form.daylight',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'ui.styleguide.form.daylight_yes' => 'yes',
                    'ui.styleguide.form.daylight_no' => 'no',
                    'ui.styleguide.form.daylight_unknown' => 'unknown',
                ],
            ])
            ->add('setting', ChoiceType::class, [
                'label' => 'ui.styleguide.form.setting',
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'chips'],
                'choices' => [
                    'ui.styleguide.sample.chip_lake' => 'lake',
                    'ui.styleguide.sample.chip_land' => 'land',
                    'ui.styleguide.sample.chip_city' => 'city',
                ],
            ])
            ->add('newsletter', CheckboxType::class, ['label' => 'ui.styleguide.form.newsletter', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'admin', 'csrf_protection' => false]);
    }

    /**
     * @return array{value: string, label: string, detail: null}
     */
    private function option(string $key): array
    {
        $text = $this->text($key);

        return ['value' => $text, 'label' => $text, 'detail' => null];
    }

    private function text(string $key): string
    {
        return $this->translator->trans('ui.styleguide.form.'.$key, [], 'admin');
    }
}
