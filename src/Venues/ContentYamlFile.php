<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidContentFolder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads, changes and writes a generated venue.yaml for #21's and #32's exports (#32 PD9).
 * Both overlay the same file, so they must parse and dump it the same way: one copy of the
 * flags keeps a second run byte-stable whichever export runs last. Comments do not survive
 * a change.
 */
final class ContentYamlFile
{
    public const int PARSE_FLAGS = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;
    public const int DUMP_FLAGS = Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

    private const int INLINE_DEPTH = 8;
    private const int INDENT = 2;

    /**
     * The document, or null when the file does not exist.
     *
     * @param list<string> $sections paths that must be maps when present, dotted for nesting
     *                               (`translations.de`)
     *
     * @throws InvalidContentFolder for a file that cannot be read; it stays as it is
     */
    public static function read(string $file, string $key, array $sections = []): ?\stdClass
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $document = Yaml::parse((string) file_get_contents($file), self::PARSE_FLAGS);
        } catch (ParseException) {
            throw InvalidContentFolder::unreadableFolder($key);
        }
        if (!$document instanceof \stdClass) {
            throw InvalidContentFolder::unreadableFolder($key);
        }
        foreach ($sections as $path) {
            $node = $document;
            foreach (explode('.', $path) as $name) {
                if (!$node instanceof \stdClass || !isset($node->{$name})) {
                    continue 2;
                }
                $node = $node->{$name};
            }
            if (!$node instanceof \stdClass) {
                throw InvalidContentFolder::unreadableFolder($key);
            }
        }

        return $document;
    }

    public static function dump(\stdClass $document): string
    {
        return Yaml::dump($document, self::INLINE_DEPTH, self::INDENT, self::DUMP_FLAGS);
    }

    /**
     * Sets a path, or removes it for null together with a section left empty.
     */
    public static function set(\stdClass $parent, string $section, string $field, mixed $value): void
    {
        if (null !== $value) {
            $parent->{$section} ??= new \stdClass();
            $parent->{$section}->{$field} = $value;

            return;
        }

        if (isset($parent->{$section})) {
            unset($parent->{$section}->{$field});
            if ([] === get_object_vars($parent->{$section})) {
                unset($parent->{$section});
            }
        }
    }

    /**
     * Writes the file through a temporary name and rename(), so a killed run never leaves
     * half a file. Creates the folder when needed.
     */
    public static function replace(string $file, string $contents): void
    {
        $folder = \dirname($file);
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $folder));
        }
        $temporary = $folder.'/.'.basename($file).'.'.bin2hex(random_bytes(4));
        if (false === file_put_contents($temporary, $contents) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Cannot write "%s".', $file));
        }
    }
}
