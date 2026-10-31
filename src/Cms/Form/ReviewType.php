<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Validator\CleanText;
use App\Venues\ValueObject\ReviewerRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The review add and edit pages (#25, CMS-8). `edit: true` adds the row token and leaves the locale out;
 * otherwise the hidden `locale` must be an enabled locale (the handler refuses any other with an
 * `\InvalidArgumentException`). Role labels come from the `venues` catalogue (`ReviewerRole::trans()`).
 */
final class ReviewType extends AbstractType
{
    public function __construct(
        private readonly EnabledLocale $locales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['edit']) {
            $builder->add('loadedAt', HiddenType::class);
        } else {
            $builder->add('locale', HiddenType::class, [
                // CleanText first: `Choice` hands the value to the ICU formatter, which throws on invalid UTF-8.
                'constraints' => [
                    new Assert\NotBlank(message: 'cms.review.locale.invalid'),
                    new Assert\Sequentially([new CleanText(message: 'cms.review.locale.invalid'), new Assert\Choice(choices: $this->locales->all(), message: 'cms.review.locale.invalid')]),
                ],
            ]);
        }

        $builder
            ->add('firstName', TextType::class, [
                'label' => 'reviews.field.first_name',
                'attr' => ['data-counter-max' => ReviewData::FIRST_NAME_MAX, 'autocomplete' => 'off'],
            ])
            ->add('role', EnumType::class, [
                'class' => ReviewerRole::class,
                'label' => 'reviews.field.role',
                'placeholder' => 'reviews.field.role_placeholder',
                'invalid_message' => 'cms.review.role.required',
                'invalid_message_parameters' => ['{{ value }}' => ''],
            ])
            ->add('month', TextType::class, [
                'label' => 'reviews.field.month',
                'help' => 'reviews.help.month',
            ])
            ->add('text', TextareaType::class, [
                'label' => 'reviews.field.text',
                'attr' => ['rows' => 6, 'data-counter-max' => ReviewData::TEXT_MAX],
            ]);
    }

    /**
     * A month picker where the browser has one (the value is `Y-m` either way). Through the view's `type`, which
     * `form_widget_simple` prints once; an `attr` would print a second `type` attribute.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->children['month']->vars['type'] = 'month';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReviewData::class,
            'translation_domain' => 'cms',
            'edit' => false,
            'validation_groups' => static fn (Options $options): array => $options['edit'] ? ['Default', 'token'] : ['Default'],
        ]);
        $resolver->setAllowedTypes('edit', 'bool');
    }
}
