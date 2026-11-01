<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Twig\RecordPropertiesExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The inline editor's runtime keys (D10): the island's labels, CrmApi's error answers and the
 * PropertyConstraints messages are built in PHP, so no template scan sees them.
 */
final class PropertyEditorKeysTest extends KernelTestCase
{
    public function test_runtime_keys_exist_in_the_catalogues(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        $crm = [...array_values(RecordPropertiesExtension::LABELS), 'api.stale', 'api.not_found', 'api.email_taken', 'api.required', 'api.unknown_choice'];
        foreach ($crm as $key) {
            self::assertTrue($catalogue->has($key, 'crm'), 'crm: '.$key);
        }

        foreach (['unknown_field', 'required', 'too_long', 'website', 'phone', 'email', 'choice', 'amount', 'date'] as $rule) {
            self::assertTrue($catalogue->has('crm.property.'.$rule, 'validators'), 'validators: crm.property.'.$rule);
        }

        foreach (['company', 'stage', 'lost_reason'] as $rule) {
            self::assertTrue($catalogue->has('crm.deal.'.$rule, 'validators'), 'validators: crm.deal.'.$rule);
        }
    }
}
