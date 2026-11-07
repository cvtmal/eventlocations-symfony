<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ReadModel\CompanyRecord;
use App\Crm\ReadModel\DealFormOption;
use App\Crm\ReadModel\DealRecord;
use App\Crm\ReadModel\OwnerOption;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\DealProperty;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The `fields` prop of the `record-properties` island (D10) for a company or deal page, in the
 * order of Company.dc.html. `value` is canonical (ids, codes), `display` what the read-only fallback
 * and the island show, `draft` the text to edit where that is not `value`. The page renders the fallback
 * from the same list, so both say the same.
 *
 * @phpstan-type Field array{name: string, label: string, type: string, value: ?string, display: ?string, draft?: ?string, required?: bool, readonly?: bool, options?: list<array{value: string, label: string}>}
 */
final readonly class RecordFieldsExtension
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<OwnerOption> $owners the selectable owners (`OwnerOptionsQuery::selectable()`)
     *
     * @return list<Field>
     */
    #[AsTwigFunction('crm_company_fields')]
    public function company(CompanyRecord $company, array $owners): array
    {
        $stages = array_map(fn (CompanyStage $stage): array => ['value' => $stage->value, 'label' => $stage->trans($this->translator)], CompanyStage::cases());

        return [
            $this->field(CompanyProperty::Name, 'text', $company->name, required: true),
            $this->field(CompanyProperty::LegalName, 'text', $company->legalName),
            $this->field(CompanyProperty::Website, 'url', $company->website),
            $this->field(CompanyProperty::Phone, 'tel', $company->phone),
            $this->field(CompanyProperty::Email, 'email', $company->email),
            $this->field(CompanyProperty::Address, 'text', $company->address),
            $this->stageField($company, $stages),
            $this->field(CompanyProperty::Owner, 'select', $company->ownerId, $company->ownerName, options: self::ownerOptions($owners)),
            $this->field(CompanyProperty::Notes, 'textarea', $company->notes),
        ];
    }

    /**
     * A deal's stored amount is Rappen and its dates `Y-m-d`, so `DealProperty` gives the text of each, as the API does.
     * A Location's Eintrag can have been released since, and a select has to offer what the deal holds.
     *
     * @param list<OwnerOption> $owners the selectable owners (`OwnerOptionsQuery::selectable()`)
     *
     * @return list<Field>
     */
    #[AsTwigFunction('crm_deal_fields')]
    public function deal(DealRecord $deal, array $owners): array
    {
        $tiers = array_map(static fn (DealFormOption $tier): array => ['value' => $tier->id, 'label' => $tier->label], $deal->tierOptions);
        $venues = array_map(static fn (DealFormOption $venue): array => ['value' => $venue->id, 'label' => $venue->label], $deal->companyVenues);
        if (null !== $deal->venue && !\in_array($deal->venue->id, array_column($venues, 'value'), true)) {
            $venues[] = ['value' => $deal->venue->id, 'label' => $deal->venue->name];
        }
        $amount = null === $deal->amountRappen ? null : (string) $deal->amountRappen;

        return [
            $this->field(DealProperty::Name, 'text', $deal->name, required: true),
            ['draft' => DealProperty::Amount->draft($amount)] + $this->field(DealProperty::Amount, 'amount', $amount, DealProperty::Amount->display($amount)),
            $this->field(DealProperty::Tier, 'select', $deal->tier?->code, $deal->tier?->name, options: $tiers),
            $this->field(DealProperty::Venue, 'select', $deal->venue?->id, $deal->venue?->name, options: $venues),
            $this->dateField(DealProperty::ContractStart, $deal->contractStartOn),
            $this->dateField(DealProperty::ContractEnd, $deal->contractEndOn),
            $this->dateField(DealProperty::ExpectedClose, $deal->expectedCloseOn),
            $this->field(DealProperty::Owner, 'select', $deal->ownerId, $deal->ownerName, options: self::ownerOptions($owners)),
        ];
    }

    /**
     * @return Field
     */
    private function dateField(DealProperty $property, ?string $stored): array
    {
        return $this->field($property, 'date', $stored, $property->display($stored));
    }

    /**
     * A company with a won deal shows its stage as "Kunde (aus Deals)" and cannot set it (#28's D9, D10).
     *
     * @param list<array{value: string, label: string}> $stages
     *
     * @return Field
     */
    private function stageField(CompanyRecord $company, array $stages): array
    {
        $label = $company->stage->trans($this->translator);
        if (!$company->stageDerived) {
            return $this->field(CompanyProperty::Stage, 'select', $company->stage->value, $label, required: true, options: $stages);
        }

        $display = $this->translator->trans('company.stage_derived', ['stage' => $label], 'crm');

        return ['readonly' => true] + $this->field(CompanyProperty::Stage, 'select', $company->stage->value, $display, required: true, options: $stages);
    }

    /**
     * @param list<array{value: string, label: string}>|null $options
     *
     * @return Field
     */
    private function field(
        CompanyProperty|DealProperty $property,
        string $type,
        ?string $value,
        ?string $display = null,
        bool $required = false,
        ?array $options = null,
    ): array {
        $field = [
            'name' => $property->value,
            'label' => $property->trans($this->translator),
            'type' => $type,
            'value' => $value,
            'display' => null === $value ? null : $display ?? $value,
        ];
        if ($required) {
            $field['required'] = true;
        }
        if (null !== $options) {
            $field['options'] = $options;
        }

        return $field;
    }

    /**
     * @param list<OwnerOption> $owners
     *
     * @return list<array{value: string, label: string}>
     */
    private static function ownerOptions(array $owners): array
    {
        return array_map(static fn (OwnerOption $owner): array => ['value' => $owner->id, 'label' => $owner->name], $owners);
    }
}
