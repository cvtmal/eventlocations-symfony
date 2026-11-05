<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The labels of the `record-properties` island (D10), translated once here so the company and
 * deal pages pass the same set: `admin_island('record-properties', {url, fields, labels: crm_property_labels()})`.
 */
final readonly class RecordPropertiesExtension
{
    /** Island prop => key in `crm`. */
    public const array LABELS = [
        'edit' => 'properties.edit',
        'save' => 'properties.save',
        'cancel' => 'properties.cancel',
        'empty' => 'properties.empty',
        'none' => 'properties.none',
        'stale' => 'properties.stale',
        'notFound' => 'properties.not_found',
        'reload' => 'properties.reload',
        'sessionGone' => 'properties.session_gone',
        'error' => 'properties.error',
        'noResults' => 'properties.no_results',
        'choose' => 'properties.choose',
        'badDate' => 'properties.bad_date',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, string>
     */
    #[AsTwigFunction('crm_property_labels')]
    public function labels(): array
    {
        return array_map(fn (string $key): string => $this->translator->trans($key, [], 'crm'), self::LABELS);
    }
}
