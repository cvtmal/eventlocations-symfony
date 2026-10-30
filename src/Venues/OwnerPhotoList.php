<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ValueObject\Content\OwnerPhoto;

/**
 * A row's gallery in display order (#32 research §Photos): the Anzeigebild cover first, then
 * the IMAGE nodes of the Vorstellungstext in text order. An id listed twice keeps its first
 * position. The cover has no alt text of its own, so it takes the alt of its own IMAGE node
 * when the same id appears there, else none and #5's fallback applies (D14).
 */
final class OwnerPhotoList
{
    public const string NOTE_COVER_MALFORMED = 'Anzeigebild is not a Wix image: no cover';
    public const string NOTE_ALT_TOO_LONG = 'an alt text longer than 700 characters: left out';

    /** #9's limit for `photos[].alt.<locale>`. */
    public const int ALT_MAX_LENGTH = 700;

    private const string COVER = '#^wix:image://v1/([^/]+)/#';

    /**
     * @param array<string, string> $row
     * @param list<string>          $notes reasons for the owner, never a cell
     *
     * @return list<OwnerPhoto>
     *
     * @throws \JsonException for a Vorstellungstext that is not JSON
     */
    public static function fromRow(array $row, array &$notes = []): array
    {
        $cover = trim($row['Anzeigebild'] ?? '');
        $coverId = null;
        if ('' !== $cover) {
            if (1 === preg_match(self::COVER, $cover, $match)) {
                $coverId = $match[1];
            } else {
                $notes[] = self::NOTE_COVER_MALFORMED;
            }
        }

        $text = $row['Vorstellungstext'] ?? '';
        $nodes = '' === trim($text) ? [] : WixRichText::images($text);

        /** @var array<string, ?string> $photos id to alt, in order */
        $photos = [];
        if (null !== $coverId) {
            $photos[$coverId] = null;
        }
        foreach ($nodes as $node) {
            if (!\array_key_exists($node['id'], $photos)) {
                $photos[$node['id']] = $node['alt'];
            } elseif ($node['id'] === $coverId && null === $photos[$coverId]) {
                $photos[$coverId] = $node['alt'];
            }
        }

        $list = [];
        $tooLong = false;
        foreach ($photos as $id => $alt) {
            if (null !== $alt && mb_strlen($alt) > self::ALT_MAX_LENGTH) {
                $alt = null;
                $tooLong = true;
            }
            $list[] = new OwnerPhoto((string) $id, $alt);
        }
        if ($tooLong) {
            $notes[] = self::NOTE_ALT_TOO_LONG;
        }

        return $list;
    }
}
