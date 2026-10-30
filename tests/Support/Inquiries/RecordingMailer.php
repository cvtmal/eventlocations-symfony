<?php

declare(strict_types=1);

namespace App\Tests\Support\Inquiries;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope as BusEnvelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * `MailerInterface` is inlined in the test container, so tests that need a failing or a
 * recording mailer build `InquiryMailer` by hand with this one. It records what would be sent
 * (unrendered: the body renderer sits in the real pipeline) and fails on the n-th send.
 *
 * `$failure` is what goes wrong inside the mail pipeline, and it surfaces as it does from the
 * real `Mailer::send()`: a transport error unwrapped, anything else as `HandlerFailedException`.
 * An error before the bus, such as a render failure, needs the real pipeline: a throwing
 * `MessageEvent` listener (`SubmitInquiryMailErrorTest`).
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $messages = [];

    private int $sends = 0;

    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly int $failOnSend = 1,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        ++$this->sends;
        if (null !== $this->failure && $this->sends === $this->failOnSend) {
            throw $this->failure instanceof TransportExceptionInterface ? $this->failure : new HandlerFailedException(new BusEnvelope(new SendEmailMessage($message, $envelope)), [$this->failure]);
        }

        \assert($message instanceof Email);
        $this->messages[] = $message;
    }
}
