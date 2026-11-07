<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Translation;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Symfony's own German messages address the reader with "Sie" (E4). The ones a reader can reach are replaced in
 * `validators+intl-icu.de.yaml`, keyed by their English source text: the CSRF refusal and a file above the PHP upload
 * limit (every form), and the default messages of `Length` (the `/konto` password and profile fields). The sources are
 * read from the framework here, not typed in, so a source text that Symfony renames lets the German "Sie" back in and
 * fails this test.
 */
final class FrameworkMessagesTest extends KernelTestCase
{
    public function test_the_framework_messages_a_reader_can_reach_are_in_du(): void
    {
        $container = self::getContainer();
        $translator = $container->get('translator');
        $forms = $container->get(FormFactoryInterface::class);
        $validator = $container->get(ValidatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(FormFactoryInterface::class, $forms);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $config = $forms->create(FormType::class)->getConfig();
        $csrf = $config->getOption('csrf_message');
        $upload = $config->getOption('upload_max_size_message');
        self::assertIsString($csrf);
        self::assertIsCallable($upload);

        $messages = [
            'csrf' => $translator->trans($csrf, [], 'validators', 'de'),
            'upload above the php limit' => (string) $upload(),
            'too short' => $this->message($validator, 'abc', new Length(min: 8)),
            'too long' => $this->message($validator, 'abcdefghij', new Length(max: 5)),
        ];

        foreach ($messages as $label => $text) {
            self::assertSame(0, preg_match('/\b(Sie|Ihnen|Ihr\w*)\b|ß|[{}]/u', $text), "{$label}: {$text}");
        }
        self::assertNotSame($csrf, $messages['csrf'], 'the CSRF message has no German text');
        self::assertStringContainsString('8', $messages['too short']);
        self::assertStringContainsString('5', $messages['too long']);
    }

    private function message(ValidatorInterface $validator, string $value, Length $constraint): string
    {
        $violations = $validator->validate($value, $constraint);
        self::assertCount(1, $violations);

        return (string) $violations->get(0)->getMessage();
    }
}
