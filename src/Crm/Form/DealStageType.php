<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\LostReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The stage form on a deal record (N8). `stages` lists the codes, from the rows.
 */
final class DealStageType extends AbstractType
{
    public const string NAME = 'deal_stage';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $stages */
        $stages = $options['stages'];

        $builder
            ->add('stage', ChoiceType::class, [
                'label' => 'deal.record.stage',
                'choices' => array_combine($stages, $stages),
                'choice_label' => static fn (string $code): string => 'deal.stage.'.$code,
            ])
            ->add('lostReason', EnumType::class, [
                'class' => LostReason::class,
                'label' => 'deal.record.lost_reason',
                'help' => 'deal.record.lost_reason_help',
                'required' => false,
                'placeholder' => 'deal.board.lost.choose',
            ])
            ->add('expected', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DealStageData::class, 'translation_domain' => 'crm']);
        $resolver->setRequired('stages');
        $resolver->setAllowedTypes('stages', 'array');
    }
}
