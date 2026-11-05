<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\DealFormCompany;
use App\Crm\ValueObject\TaskDueDate;
use App\Venues\ValueObject\Tier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The second step of "Deal erfassen" (N5). A new deal takes its Location's Eintrag (#56 D7), so the form has no
 * Eintrag field; `owners` are the selectable staff accounts. A new deal always starts at `new`, so there is no stage.
 */
final class DealType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'deal.property.name', 'constraints' => FieldRules::of(PropertyConstraints::dealName()), 'help' => 'create.deal.name_help', 'attr' => ['data-counter-max' => 150]])
            ->add('tier', EnumType::class, ['class' => Tier::class, 'label' => 'deal.property.tier', 'required' => false, 'placeholder' => 'create.deal.tier_none'])
            ->add('amount', ChfTextType::class, ['label' => 'deal.property.amount', 'required' => false])
            ->add('contractStart', DateType::class, self::day('deal.property.contract_start'))
            ->add('contractEnd', DateType::class, self::day('deal.property.contract_end'))
            ->add('expectedClose', DateType::class, self::day('deal.property.expected_close'))
            ->add('owner', ChoiceType::class, ['label' => 'deal.property.owner', 'required' => false, 'placeholder' => 'create.owner_none', ...OwnerChoices::options($options['owners'])]);
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
}
