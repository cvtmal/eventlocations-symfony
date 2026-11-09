<?php

declare(strict_types=1);

namespace App\Factory;

use App\Inquiries\Entity\InquiryBrief;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * The parent of a brief to several venues (F13). Its children are `InquiryFactory` rows with `'brief' => $brief`.
 *
 * @extends PersistentObjectFactory<InquiryBrief>
 */
final class InquiryBriefFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return InquiryBrief::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venueCount' => 3,
            'fingerprint' => self::faker()->sha256(),
            'createdAt' => new \DateTimeImmutable(),
        ];
    }
}
