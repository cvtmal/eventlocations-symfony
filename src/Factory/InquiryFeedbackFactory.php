<?php

declare(strict_types=1);

namespace App\Factory;

use App\Inquiries\Entity\InquiryFeedback;
use App\Inquiries\ValueObject\FeedbackOutcome;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * The feedback row of an inquiry (G16): mailed at `at` (now by default), not answered. The factory writes what it is
 * given, without the job's rules: a test can store feedback for an inquiry that would never get the mail.
 *
 * @extends PersistentObjectFactory<InquiryFeedback>
 */
final class InquiryFeedbackFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return InquiryFeedback::class;
    }

    public function answered(bool $venueReplied, FeedbackOutcome $outcome, \DateTimeImmutable $at): self
    {
        return $this->afterInstantiate(static function (InquiryFeedback $feedback) use ($venueReplied, $outcome, $at): void {
            $feedback->answer($venueReplied, $outcome, $at);
        });
    }

    /**
     * The address stopped the mails; `skipped` also leaves `mailed_at` empty, as the job does for a later inquiry.
     */
    public function optedOut(bool $skipped = false): self
    {
        return $this->instantiateWith(Instantiator::namedConstructor($skipped ? 'skipped' : 'mailed'))
            ->afterInstantiate(static function (InquiryFeedback $feedback, array $attributes): void {
                \assert($attributes['at'] instanceof \DateTimeImmutable);
                $feedback->optOut($attributes['at']);
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'inquiry' => InquiryFactory::new(),
            'at' => new \DateTimeImmutable(),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::namedConstructor('mailed'));
    }
}
