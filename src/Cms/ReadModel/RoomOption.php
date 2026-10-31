<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * A room a photo can be assigned to (#25): its name in the requested locale, else the default
 * locale's, else '' (a room imported without a translation).
 */
final readonly class RoomOption
{
    public function __construct(
        public string $id,
        public string $name,
        public int $position,
    ) {
    }

    /**
     * @return array{id: string, name: string, position: int}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'position' => $this->position];
    }
}
