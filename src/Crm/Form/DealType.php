<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\DealFormCompany;
use App\Crm\ReadModel\DealFormOption;
use App\Crm\ValueObject\TaskDueDate;
use App\Crm\ValueObject\VerbatimText;
use App\Venues\ValueObject\Tier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The second step of "Deal erfassen" (N5). `company` brings its linked venues and its contacts, so a foreign one is no
 * choice at all; `owners` are the selectable staff accounts. A new deal always starts at `new`, so there is no stage.
 */
final class DealType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var DealFormCompany $company */
        $company = $options['company'];

        $builder
            ->add('name', TextType::class, ['label' => 'deal.property.name', 'constraints' => FieldRules::of(PropertyConstraints::dealName()), 'help' => 'create.deal.name_help', 'attr' => ['data-counter-max' => 150]])
            ->add('venue', ChoiceType::class, ['label' => 'deal.property.venue', 'required' => false, 'placeholder' => 'create.deal.venue_none', ...self::options($company->venues)])
            ->add('tier', EnumType::class, ['class' => Tier::class, 'label' => 'deal.property.tier', 'required' => false, 'placeholder' => 'create.deal.tier_none'])
            ->add('amount', ChfTextType::class, ['label' => 'deal.property.amount', 'required' => false])
            ->add('contractStart', DateType::class, self::day('deal.property.contract_start'))
            ->add('contractEnd', DateType::class, self::day('deal.property.contract_end'))
            ->add('expectedClose', DateType::class, self::day('deal.property.expected_close'))
            ->add('owner', ChoiceType::class, ['label' => 'deal.property.owner', 'required' => false, 'placeholder' => 'create.owner_none', ...OwnerChoices::options($options['owners'])]);

        if ([] !== $company->contacts) {
            $builder->add('contacts', ChoiceType::class, ['label' => 'create.deal.contacts', 'required' => false, 'multiple' => true, 'expanded' => true, ...self::options($company->contacts)]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DealData::class, 'translation_domain' => 'crm']);
        $resolver->setRequired(['company', 'owners']);
        $resolver->setAllowedTypes('company', DealFormCompany::class);
        $resolver->setAllowedTypes('owners', 'array');
    }

    /**
     * @return array<string, mixed>
     */
    private static function day(string $label): array
    {
        return [
            'label' => $label,
            'required' => false,
            'widget' => 'single_text',
            'input' => 'string',
            'invalid_message' => 'crm.property.date',
            'constraints' => [new Assert\Regex(pattern: TaskDueDate::SHAPE, message: 'crm.property.date')],
        ];
    }

    /**
     * Ids as values, names as labels, verbatim.
     *
     * @param list<DealFormOption> $options
     *
     * @return array{choices: array<string, string>, choice_label: \Closure(string): TranslatableInterface}
     */
    private static function options(array $options): array
    {
        $labels = [];
        foreach ($options as $option) {
            $labels[$option->id] = $option->label;
        }
        $ids = array_keys($labels);

        return [
            'choices' => array_combine($ids, $ids),
            'choice_label' => static fn (string $id): TranslatableInterface => new VerbatimText($labels[$id] ?? $id),
        ];
    }
}
