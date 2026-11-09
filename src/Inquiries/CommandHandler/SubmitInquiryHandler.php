<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\SubmitInquiry;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Exception\InquiryNotAccepted;
use App\Inquiries\InquiryRules;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\InquiryFingerprint;
use App\Inquiries\ValueObject\SubmittedInquiry;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Repository\VenueRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Business rules of an inquiry (the handler layer of the validation strategy, `InquiryRules`) and the row.
 *
 * One guest action is one row: the same inquiry again within the window returns the row it
 * repeats, whatever that row's mail status, and `DeliverInquiry` decides what is left to do. The
 * form works without JS, and PHP finishes a POST the browser has cancelled, so this is the only
 * place that sees both POSTs of a double click. The advisory lock is what makes it hold for two
 * at once: it keeps the second one waiting until this transaction has committed the row.
 */
final class SubmitInquiryHandler implements CommandHandlerInterface
{
    private const REPEAT_WINDOW = '-10 minutes';

    public function __construct(
        private readonly VenueRepository $venues,
        private readonly InquiryRepository $inquiries,
        private readonly InquiryRules $rules,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function __invoke(SubmitInquiry $command): SubmittedInquiry
    {
        $venue = $this->venues->find($command->venueId);
        if (null === $venue || !$venue->isPublished() || null === $venue->inquiryEmail()) {
            throw InquiryNotAccepted::forVenue($command->venueId);
        }

        $now = $this->clock->now();
        $this->rules->check($command, $now);

        $fingerprint = InquiryFingerprint::of($command, $this->secret)->value;
        $this->inquiries->lockFingerprint($fingerprint);

        $repeated = $this->inquiries->findLatestIdByFingerprint($fingerprint, $now->modify(self::REPEAT_WINDOW));
        if (null !== $repeated) {
            return new SubmittedInquiry($repeated, true);
        }

        $inquiry = new Inquiry(
            $venue,
            $command->venueSlug,
            $command->locale,
            $command->eventType,
            $command->company,
            $command->guests,
            null === $command->eventDate ? null : new \DateTimeImmutable($command->eventDate),
            $command->dateFlexible,
            InquiryRules::startTime($command->startTime),
            $command->cateringWish,
            $command->budgetChf,
            $command->message,
            $command->firstName,
            $command->lastName,
            $command->email,
            $command->phone,
            $fingerprint,
            $now,
        );
        $this->inquiries->add($inquiry);

        return new SubmittedInquiry($inquiry->id(), false);
    }
}
