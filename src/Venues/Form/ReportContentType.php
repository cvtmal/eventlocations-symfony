<?php

declare(strict_types=1);

namespace App\Venues\Form;

use App\Venues\ValueObject\ModerationKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The complaint form of `/inhalt-melden` (G2). Labels are in `venues/report/show.html.twig`. With a venue
 * (`photo_ids` lists its public photos) a rights complaint can tick photos; without one, `venueReference` asks
 * which location is meant. `website` is the honeypot. CSRF is the stateless check of every public form.
 */
final class ReportContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('kind', EnumType::class, [
            'class' => ModerationKind::class,
            'expanded' => true,
            'invalid_message' => 'report.kind.required',
        ]);
        if ([] !== $options['photo_ids']) {
            $builder->add('photos', ChoiceType::class, [
                'choices' => array_combine($options['photo_ids'], $options['photo_ids']),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'invalid_message' => 'report.photos.invalid',
            ]);
        }
        if (!$options['has_venue']) {
            $builder->add('venueReference', TextType::class);
        }
        $builder
            ->add('message', TextareaType::class, ['attr' => ['rows' => 7, 'maxlength' => ReportContentData::MESSAGE_MAX]])
            ->add('name', TextType::class, ['attr' => ['autocomplete' => 'name', 'maxlength' => 100]])
            ->add('email', EmailType::class, ['attr' => ['autocomplete' => 'email', 'maxlength' => 254]])
            ->add('goodFaith', CheckboxType::class)
            ->add('source', HiddenType::class)
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'tabindex' => -1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReportContentData::class,
            'translation_domain' => 'venues',
            'photo_ids' => [],
            'has_venue' => false,
        ]);
        $resolver->setAllowedTypes('photo_ids', 'string[]');
        $resolver->setAllowedTypes('has_venue', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'report';
    }
}
