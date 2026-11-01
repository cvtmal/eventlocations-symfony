<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\CloseMonth;
use App\Crm\ValueObject\VerbatimText;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The filter of the deal board and list: a GET form in the query string (`createNamed('')`), as the company list.
 * `stages` are the codes of `crm_deal_stages`, `months` come from `DealListQuery::months()`. `ansicht`, `sort`, `dir` and
 * `page` are read beside it (`allow_extra_fields`).
 */
final class DealFilterType extends AbstractType
{
    /**
     * The months a filter offers, plus the one in the URL: a saved view stays valid when its last deal moves away (D12).
     *
     * @param list<string> $months
     *
     * @return list<string>
     */
    public static function monthsFor(array $months, Request $request): array
    {
        $requested = ListQueryString::value($request, 'abschluss');
        if (1 !== preg_match(CloseMonth::SHAPE, $requested) || \in_array($requested, $months, true)) {
            return $months;
        }

        $months[] = $requested;
        sort($months);

        return $months;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $stages */
        $stages = $options['stages'];
        /** @var list<string> $months */
        $months = $options['months'];

        $builder
            ->add('q', SearchType::class, ['label' => 'list.filter.search', 'required' => false])
            ->add('stage', ChoiceType::class, [
                'label' => 'list.filter.stage',
                'required' => false,
                'placeholder' => 'list.filter.all_stages',
                'choices' => array_combine($stages, $stages),
                'choice_label' => static fn (string $code): string => 'deal.stage.'.$code,
            ])
            ->add('owner', ChoiceType::class, [
                'label' => 'list.filter.owner',
                'required' => false,
                'placeholder' => 'list.filter.all_owners',
                ...OwnerChoices::options($options['owners'], withNone: true),
            ])
            ->add('abschluss', ChoiceType::class, [
                'label' => 'list.filter.close',
                'property_path' => 'month',
                'required' => false,
                'placeholder' => 'list.filter.all_close',
                'choices' => array_combine($months, $months),
                'choice_label' => static fn (string $month): TranslatableInterface => new VerbatimText(CloseMonth::label($month)),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DealFilterData::class,
            'translation_domain' => 'crm',
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired(['owners', 'stages', 'months']);
        $resolver->setAllowedTypes('owners', 'array');
        $resolver->setAllowedTypes('stages', 'array');
        $resolver->setAllowedTypes('months', 'array');
    }
}
