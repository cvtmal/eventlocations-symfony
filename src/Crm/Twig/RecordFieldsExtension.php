<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ReadModel\CompanyRecord;
use App\Crm\ReadModel\ContactRecord;
use App\Crm\ReadModel\OwnerOption;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The `fields` prop of the `record-properties` island (D10) for a company or contact page, in the
 * order of Company.dc.html. `value` is canonical (ids, codes), `display` what the read-only fallback
 * and the island show. The page renders the fallback from the same list, so both say the same.
 *
 * @phpstan-type Field array{name: string, label: string, type: string, value: ?string, display: ?string, required?: bool, options?: list<array{value: string, label: string}>, lookupUrl?: string}
 */
final readonly class RecordFieldsExtension
{
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
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
            $this->field(CompanyProperty::Stage, 'select', $company->stage->value, $company->stage->trans($this->translator), required: true, options: $stages),
            $this->field(CompanyProperty::Owner, 'select', $company->ownerId, $company->ownerName, options: self::ownerOptions($owners)),
            $this->field(CompanyProperty::Notes, 'textarea', $company->notes),
        ];
    }

    /**
     * @param list<OwnerOption> $owners the selectable owners (`OwnerOptionsQuery::selectable()`)
     *
     * @return list<Field>
     */
    #[AsTwigFunction('crm_contact_fields')]
    public function contact(ContactRecord $contact, array $owners): array
    {
        $kinds = array_map(fn (ContactKind $kind): array => ['value' => $kind->value, 'label' => $kind->trans($this->translator)], ContactKind::cases());

        return [
            $this->field(ContactProperty::FirstName, 'text', $contact->firstName),
            $this->field(ContactProperty::LastName, 'text', $contact->lastName, required: true),
            $this->field(ContactProperty::Kind, 'select', $contact->kind->value, $contact->kind->trans($this->translator), required: true, options: $kinds),
            $this->field(ContactProperty::Company, 'combobox', $contact->companyId, $contact->companyName, lookupUrl: $this->urls->generate('admin_api_crm_companies')),
            $this->field(ContactProperty::Position, 'text', $contact->position),
            $this->field(ContactProperty::Email, 'email', $contact->email),
            $this->field(ContactProperty::Phone, 'tel', $contact->phone),
            $this->field(ContactProperty::Owner, 'select', $contact->ownerId, $contact->ownerName, options: self::ownerOptions($owners)),
        ];
    }

    /**
     * @param list<array{value: string, label: string}>|null $options
     *
     * @return Field
     */
    private function field(
        CompanyProperty|ContactProperty $property,
        string $type,
        ?string $value,
        ?string $display = null,
        bool $required = false,
        ?array $options = null,
        ?string $lookupUrl = null,
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
        if (null !== $lookupUrl) {
            $field['lookupUrl'] = $lookupUrl;
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
