<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Room layouts (room-layouts, F4): two more layouts per room, and a room's guest maximum follows its layouts.
 *
 * - rooms: capacity_u_shape_{min,max} and capacity_block_{min,max} with the same range CHECK as the four
 *   forked layouts (one pair per `Seating` case).
 * - When a layout states a maximum, capacity_max is the greatest layout maximum (chk_rooms_capacity_layouts) and
 *   capacity_min the least minimum of those layouts (written by `Room`, not checked). Existing rows are
 *   back-filled before the CHECK goes on. down() drops the columns and the CHECK; the back-filled values stay.
 */
final class Version20261107184701 extends AbstractMigration
{
    private const LAYOUTS = ['seminar', 'banquet', 'apero', 'concert', 'u_shape', 'block'];

    public function getDescription(): string
    {
        return 'Room layouts: U-Form and Block capacities, room maximum = greatest layout maximum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rooms ADD COLUMN capacity_u_shape_min INTEGER DEFAULT NULL, ADD COLUMN capacity_u_shape_max INTEGER DEFAULT NULL, ADD COLUMN capacity_block_min INTEGER DEFAULT NULL, ADD COLUMN capacity_block_max INTEGER DEFAULT NULL');
        foreach (['u_shape', 'block'] as $layout) {
            $this->addSql(\sprintf(
                'ALTER TABLE rooms ADD CONSTRAINT chk_rooms_capacity_%1$s CHECK ((capacity_%1$s_min IS NULL OR capacity_%1$s_min BETWEEN 1 AND 50000) AND (capacity_%1$s_max IS NULL OR capacity_%1$s_max BETWEEN 1 AND 50000) AND (capacity_%1$s_min IS NULL OR capacity_%1$s_max IS NULL OR capacity_%1$s_min <= capacity_%1$s_max))',
                $layout,
            ));
        }

        $greatest = $this->greatestMax();
        $least = 'LEAST('.implode(', ', array_map(static fn (string $l): string => "CASE WHEN capacity_{$l}_max IS NOT NULL THEN capacity_{$l}_min END", self::LAYOUTS)).')';
        $this->addSql("UPDATE rooms SET capacity_max = {$greatest}, capacity_min = {$least} WHERE {$greatest} IS NOT NULL AND (capacity_max IS DISTINCT FROM {$greatest} OR capacity_min IS DISTINCT FROM {$least})");
        $this->addSql("ALTER TABLE rooms ADD CONSTRAINT chk_rooms_capacity_layouts CHECK ({$greatest} IS NULL OR capacity_max IS NOT DISTINCT FROM {$greatest})");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rooms DROP CONSTRAINT chk_rooms_capacity_layouts, DROP CONSTRAINT chk_rooms_capacity_u_shape, DROP CONSTRAINT chk_rooms_capacity_block');
        $this->addSql('ALTER TABLE rooms DROP COLUMN capacity_u_shape_min, DROP COLUMN capacity_u_shape_max, DROP COLUMN capacity_block_min, DROP COLUMN capacity_block_max');
    }

    private function greatestMax(): string
    {
        return 'GREATEST('.implode(', ', array_map(static fn (string $l): string => "capacity_{$l}_max", self::LAYOUTS)).')';
    }
}
