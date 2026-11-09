<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use App\Inquiries\ReadModel\BriefCandidate;
use App\Inquiries\ValueObject\BriefRules;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The form of a brief to several venues (F13): the single inquiry's fields (`InquiryType`) plus `venues`, one checkbox
 * per offered venue, each named. That list is the consent step: the brief goes to exactly the ticked venues. The
 * first five are ticked to start with; after a refused POST the guest's own ticks are kept where still offered.
 */
final readonly class BriefFormFactory
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<BriefCandidate> $offered
     * @param list<int>            $numbers the `nr` the page was asked for, kept in the action
     * @param list<int>|null       $ticked  null ticks the first five
     */
    public function create(array $offered, array $numbers, ?InquiryData $data = null, ?array $ticked = null): FormInterface
    {
        if (null === $data) {
            $data = new InquiryData();
            $data->message = $this->translator->trans('brief.message_default', [], 'inquiries');
        }
        $byNumber = [];
        foreach ($offered as $candidate) {
            $byNumber[$candidate->number] = $candidate;
        }
        $offeredNumbers = array_keys($byNumber);
        $ticked = null === $ticked
            ? \array_slice($offeredNumbers, 0, BriefRules::MAX_VENUES)
            : array_values(array_intersect($ticked, $offeredNumbers));

        return $this->forms->createBuilder(InquiryType::class, $data, [
            'action' => $this->urls->generate('inquiry_brief', ['nr' => implode(',', $numbers)]),
        ])
            ->add('venues', ChoiceType::class, [
                'mapped' => false,
                'expanded' => true,
                'multiple' => true,
                'choices' => array_combine(array_map('strval', $offeredNumbers), $offeredNumbers),
                'choice_label' => static fn (int $number): string => $byNumber[$number]->name.(null === $byNumber[$number]->town ? '' : ', '.$byNumber[$number]->town),
                'choice_translation_domain' => false,
                'data' => $ticked,
                'constraints' => [new Count(
                    min: BriefRules::MIN_VENUES,
                    max: BriefRules::MAX_VENUES,
                    minMessage: 'inquiry.venues.count',
                    maxMessage: 'inquiry.venues.count',
                )],
            ])
            ->getForm();
    }
}
