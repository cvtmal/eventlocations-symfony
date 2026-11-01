<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\DealFormOption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Picks one of the company's contacts that are not on the deal yet. `contacts` is a list of `DealFormOption`.
 */
final class DealContactType extends AbstractType
{
    public const string NAME = 'deal_contact';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<DealFormOption> $contacts */
        $contacts = $options['contacts'];

        $builder->add('contact', ChoiceType::class, [
            'label' => 'deal.record.contacts.field',
            'choices' => array_column($contacts, 'id', 'label'),
            'choice_translation_domain' => false,
            'placeholder' => 'deal.board.lost.choose',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DealContactData::class, 'translation_domain' => 'crm']);
        $resolver->setRequired('contacts');
        $resolver->setAllowedTypes('contacts', 'array');
    }
}
