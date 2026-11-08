<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\OutreachCohort;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "Akquise" tab's fields (G11); every one may stay empty.
 */
final class OutreachType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('campaignCode', TextType::class, [
                'label' => 'outreach.field.campaign_code',
                'help' => 'outreach.field.campaign_code_help',
                'required' => false,
                'attr' => ['maxlength' => 40, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('cohort', EnumType::class, self::choice(OutreachCohort::class, 'outreach.field.cohort'))
            ->add('channel', EnumType::class, self::choice(ContactChannel::class, 'outreach.field.channel'))
            ->add('basis', EnumType::class, self::choice(ContactBasis::class, 'outreach.field.basis'))
            ->add('sentOn', DateType::class, self::date('outreach.field.sent_on'))
            ->add('returnedOn', DateType::class, self::date('outreach.field.returned_on'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OutreachData::class, 'translation_domain' => 'crm']);
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return array<string, mixed>
     */
    private static function choice(string $enum, string $label): array
    {
        return ['class' => $enum, 'label' => $label, 'required' => false, 'placeholder' => 'outreach.field.none', 'invalid_message' => 'crm.property.choice'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function date(string $label): array
    {
        return ['label' => $label, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'invalid_message' => 'crm.property.date'];
    }
}
