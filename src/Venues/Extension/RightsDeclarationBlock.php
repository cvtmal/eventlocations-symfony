<?php

declare(strict_types=1);

namespace App\Venues\Extension;

use App\Shared\Extension\ContentBlockDefinition;
use App\Shared\Extension\ContentBlockPageDefault;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The text of the rights declaration (G1) a manager agrees to at the submit step and on the Fotos
 * tab. Staff edit it in the text blocks screen; until they do, the `venues` catalogue's
 * `rights.declaration.default` is the text. `Venues\RightsPolicy` turns whatever is in force into
 * a version, which every grant records, so a staff edit never rewrites what an earlier grantor
 * agreed to. The default is Markdown of several paragraphs, which `fallback()` (one paragraph)
 * cannot say; hence `pageDefault()`, as the home text does.
 */
final class RightsDeclarationBlock implements ContentBlockDefinition, ContentBlockPageDefault
{
    public const string KEY = 'rights.declaration';

    public const string DEFAULT_MESSAGE = 'rights.declaration.default';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.rights_declaration.label', [], 'venues');
    }

    public function placement(): TranslatableInterface
    {
        return new TranslatableMessage('blocks.rights_declaration.placement', [], 'venues');
    }

    public function maxLength(): int
    {
        return 1500;
    }

    public function fallback(): ?TranslatableInterface
    {
        return null;
    }

    public function pageDefault(): array
    {
        return [new TranslatableMessage(self::DEFAULT_MESSAGE, [], 'venues')];
    }
}
