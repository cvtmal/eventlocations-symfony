<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\Command\IssueVenuePreview;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PreviewLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('campaignCode', TextType::class, [
                'label' => 'preview_links.field.campaign',
                'help' => 'preview_links.field.campaign_help',
                'attr' => ['maxlength' => 40, 'autocomplete' => 'off', 'spellcheck' => 'false'],
                'invalid_message_parameters' => ['{{ value }}' => ''],
            ])
            ->add('lifetimeDays', ChoiceType::class, [
                'label' => 'preview_links.field.lifetime',
                'choices' => array_combine(array_map(strval(...), IssueVenuePreview::LIFETIMES), IssueVenuePreview::LIFETIMES),
                'choice_label' => static fn (int $days): string => 'preview_links.lifetime.'.$days,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PreviewLinkData::class, 'translation_domain' => 'cms']);
    }
}
