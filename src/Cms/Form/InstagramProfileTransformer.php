<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\ValueObject\InstagramProfile;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * The contact card's Instagram (#45 F-m3): a handle, `@handle` or a profile URL is stored as
 * `https://www.instagram.com/<handle>`, so `chk_venues_instagram` holds and the link works. A stored value is shown
 * as that link when it names a profile.
 *
 * A stored value that names no profile (the old `UrlType` saved a dotted handle typed without `@` as
 * `https://remise.rebhalde`) is shown as it is and, sent back unchanged, kept as it is: a save of another field on the
 * card is never blocked by a field nobody touched, and the public page still shows no link for it. Only a changed
 * value that names no profile is a field error (the field's `invalid_message`). One instance per built form, so the
 * remembered value is that form's.
 *
 * @implements DataTransformerInterface<string, string>
 */
final class InstagramProfileTransformer implements DataTransformerInterface
{
    private ?string $unreadable = null;

    public function transform(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }
        $profile = InstagramProfile::fromInput($value);
        if (null === $profile) {
            $this->unreadable = $value;

            return $value;
        }

        return $profile->url();
    }

    public function reverseTransform(mixed $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        if (null !== $this->unreadable && trim($value) === trim($this->unreadable)) {
            return $this->unreadable;
        }

        return InstagramProfile::fromInput($value)?->url() ?? throw new TransformationFailedException('Not an Instagram handle or profile URL.');
    }
}
