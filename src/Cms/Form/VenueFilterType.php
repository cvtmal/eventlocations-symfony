<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueListFilter;
use App\Geo\ValueObject\Canton;
use App\Venues\ValueObject\Tier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The venue list's filter: a GET form in the query string (`createNamed('')`), so a filtered list has a
 * URL, as the audit screen's. `sort`, `dir` and `page` are read beside it (`allow_extra_fields`).
 */
final class VenueFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $cantons = [VenueListFilter::CANTON_NONE];
        foreach (Canton::cases() as $canton) {
            $cantons[] = $canton->value;
        }

        $builder
            ->add('q', SearchType::class, ['label' => 'list.filter.search', 'required' => false])
            ->add('tier', EnumType::class, ['class' => Tier::class, 'label' => 'list.filter.tier', 'required' => false, 'placeholder' => 'list.filter.all_tiers'])
            ->add('canton', ChoiceType::class, [
                'label' => 'list.filter.canton',
                'required' => false,
                'placeholder' => 'list.filter.all_cantons',
                'choices' => array_combine($cantons, $cantons),
                'choice_label' => static fn (string $code): TranslatableInterface => VenueListFilter::CANTON_NONE === $code
                    ? new TranslatableMessage('list.filter.canton_none', [], 'cms')
                    : Canton::from($code),
            ])
            ->add('address', ChoiceType::class, [
                'label' => 'list.filter.address',
                'required' => false,
                'placeholder' => 'list.filter.all_addresses',
                'choices' => ['list.filter.address_missing' => VenueFilterData::ADDRESS_MISSING],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'list.filter.status',
                'required' => false,
                'choices' => array_combine(VenueListFilter::STATUSES, VenueListFilter::STATUSES),
                'choice_label' => static fn (string $status): string => 'list.status.'.$status,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VenueFilterData::class,
            'translation_domain' => 'cms',
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }
}
