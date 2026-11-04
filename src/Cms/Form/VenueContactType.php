<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueContactType extends VenueCardType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('phone', TelType::class, [
                'label' => 'contact.field.phone',
                'help' => 'contact.help.phone',
                'required' => false,
                'invalid_message' => 'cms.venue.phone.invalid',
                'invalid_message_parameters' => ['{{ value }}' => ''],
            ])
            ->add('inquiryEmail', EmailType::class, ['label' => 'contact.field.inquiry_email', 'help' => 'contact.help.inquiry_email', 'required' => false])
            ->add('website', UrlType::class, ['label' => 'contact.field.website', 'required' => false, 'default_protocol' => 'https'])
            ->add('contactPerson', TextType::class, ['label' => 'contact.field.contact_person', 'required' => false])
            // Text, not `UrlType`: its default protocol turned `@handle` into `https://@handle`, a dead link (#45 F-m3).
            ->add('instagram', TextType::class, [
                'label' => 'contact.field.instagram',
                'help' => 'contact.help.instagram',
                'required' => false,
                'invalid_message' => 'cms.venue.instagram.invalid',
                'invalid_message_parameters' => ['{{ value }}' => ''],
            ]);

        $builder->get('phone')->addModelTransformer(new PhoneNumberTransformer());
        $builder->get('instagram')->addModelTransformer(new InstagramProfileTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueContactData::class);
    }
}
