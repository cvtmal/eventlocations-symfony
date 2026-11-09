<?php

declare(strict_types=1);

namespace App\Tests\Support\Inquiries;

use App\Factory\InquiryFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\QualificationReason;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G16: an inquiry whose venue mail went out at `$sentAt`, qualified unless a reason or a staff mark is given.
 */
trait StoresDeliveredInquiries
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function delivered(string $sentAt, array $attributes = [], ?QualificationReason $reason = null, ?InquiryOverride $mark = null): Inquiry
    {
        $at = new \DateTimeImmutable($sentAt);
        $inquiry = InquiryFactory::createOne(['createdAt' => $at, ...$attributes]);
        $inquiry->recordVenueMailSent($inquiry->recipientEmail(), $at);
        $inquiry->recordQualification($reason, $at);
        $inquiry->mark($mark, $at);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->flush();

        return $inquiry;
    }
}
