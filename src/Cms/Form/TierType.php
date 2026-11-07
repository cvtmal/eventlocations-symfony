<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\ReadModel\TierDefinitionsQuery;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The tier form (F12): the name, the price in francs and its VAT line, the rank weight, four limits, three flags and the event types
 * a venue may pick. The code (option `code`) is shown by the page and never edited; it is here so that the name check
 * leaves the tier itself out. A name another tier has is a field error before any command is dispatched
 * (`uk_tiers_name`; the handler and the key still decide a race). A whole number that is no number is the field's
 * `invalid_message`, with `{{ value }}` emptied (see `FrancsTextType`).
 */
final class TierType extends AbstractType
{
    private const array WHOLE_NUMBER = [
        'invalid_message' => 'cms.tier.number.invalid',
        'invalid_message_parameters' => ['{{ value }}' => ''],
    ];

    public function __construct(
        private readonly TierDefinitionsQuery $tiers,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $code = $options['code'];
        \assert(\is_string($code));

        $builder
            ->add('name', TextType::class, [
                'label' => 'tiers.editor.field.name',
                'attr' => ['data-counter-max' => 60],
                'constraints' => [new Callback(function (?string $name, ExecutionContextInterface $context) use ($code): void {
                    if ($this->belongsToAnotherTier($name, $code)) {
                        $context->buildViolation('cms.tier.name.taken')->addViolation();
                    }
                })],
            ])
            ->add('priceRappen', FrancsTextType::class, ['label' => 'tiers.editor.field.price', 'help' => 'tiers.editor.help.price'])
            ->add('vatLine', CheckboxType::class, [
                'label' => 'tiers.editor.field.vat_line',
                'help' => 'tiers.editor.help.vat_line',
                'required' => false,
            ])
            ->add('rankWeight', IntegerType::class, [
                'label' => 'tiers.editor.field.rank_weight',
                'help' => 'tiers.editor.help.rank_weight',
                'attr' => ['min' => 0, 'max' => TierData::MAX_RANK_WEIGHT],
            ] + self::WHOLE_NUMBER)
            ->add('photosMax', IntegerType::class, [
                'label' => 'tiers.editor.field.photos_max',
                'help' => 'tiers.editor.help.photos_max',
                'attr' => ['min' => 0, 'max' => TierData::MAX_COUNT],
            ] + self::WHOLE_NUMBER)
            ->add('roomsMax', IntegerType::class, [
                'label' => 'tiers.editor.field.rooms_max',
                'help' => 'tiers.editor.help.rooms_max',
                'attr' => ['min' => 0, 'max' => TierData::MAX_COUNT],
            ] + self::WHOLE_NUMBER)
            ->add('videosMax', IntegerType::class, [
                'label' => 'tiers.editor.field.videos_max',
                'help' => 'tiers.editor.help.videos_max',
                'attr' => ['min' => 0, 'max' => TierData::MAX_COUNT],
            ] + self::WHOLE_NUMBER)
            ->add('documentsMax', IntegerType::class, [
                'label' => 'tiers.editor.field.documents_max',
                'help' => 'tiers.editor.help.documents_max',
                'attr' => ['min' => 0, 'max' => TierData::MAX_COUNT],
            ] + self::WHOLE_NUMBER)
            ->add('contactVisible', CheckboxType::class, [
                'label' => 'tiers.editor.field.contact_visible',
                'help' => 'tiers.editor.help.contact_visible',
                'required' => false,
            ])
            ->add('lastMinuteAllowed', CheckboxType::class, [
                'label' => 'tiers.editor.field.last_minute_allowed',
                'help' => 'tiers.editor.help.last_minute_allowed',
                'required' => false,
            ])
            ->add('detailedReport', CheckboxType::class, [
                'label' => 'tiers.editor.field.detailed_report',
                'help' => 'tiers.editor.help.detailed_report',
                'required' => false,
            ])
            ->add('eventTypesMax', IntegerType::class, [
                'label' => 'tiers.editor.field.event_types_max',
                'help' => 'tiers.editor.help.event_types_max',
                'required' => false,
                'attr' => ['min' => 1, 'max' => TierData::MAX_COUNT],
            ] + self::WHOLE_NUMBER);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TierData::class, 'translation_domain' => 'cms']);
        $resolver->setRequired('code');
        $resolver->setAllowedTypes('code', 'string');
    }

    /**
     * A name the database cannot take (invalid UTF-8, a NUL) is `CleanText`'s to refuse, and the lookup must not meet
     * it: the database would answer with an error, not with "no".
     */
    private function belongsToAnotherTier(?string $name, string $code): bool
    {
        if (null === $name || '' === $name || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")) {
            return false;
        }

        $holder = $this->tiers->codeByName($name);

        return null !== $holder && $holder !== $code;
    }
}
