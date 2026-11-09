<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryBriefFactory;
use App\Factory\InquiryFactory;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Entity\InquiryBrief;
use App\Inquiries\ValueObject\MailStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * F13: the guest's one confirmation also comes when the brief reached no venue at first and staff resend a child
 * (`DeliverInquiry`, the CRM resend), once, and never while a sibling still waits for its first delivery.
 */
final class BriefConfirmationTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private EntityManagerInterface $em;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->bus = $bus;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_resend_of_an_undelivered_brief_sends_the_confirmation_once(): void
    {
        $brief = InquiryBriefFactory::createOne(['venueCount' => 2]);
        $first = $this->child($brief, MailStatus::Failed);
        $second = $this->child($brief, MailStatus::Failed);

        $this->bus->dispatch(new DeliverInquiry($first->id()));

        self::assertSame(1, $this->confirmations());
        self::assertSame('sent', $this->connection->fetchOne('SELECT guest_mail_status FROM inquiry_briefs WHERE id = ?', [$brief->id()]));

        $this->bus->dispatch(new DeliverInquiry($second->id()));

        self::assertSame(1, $this->confirmations(), 'a sent confirmation is never repeated');
        self::assertSame(['skipped', 'skipped'], $this->connection->fetchFirstColumn('SELECT guest_mail_status FROM inquiries ORDER BY id'));
    }

    public function test_the_confirmation_waits_for_a_sibling_without_a_first_delivery(): void
    {
        $brief = InquiryBriefFactory::createOne(['venueCount' => 2]);
        $first = $this->child($brief, MailStatus::Pending);
        $this->child($brief, MailStatus::Pending);

        $this->bus->dispatch(new DeliverInquiry($first->id()));

        self::assertSame(0, $this->confirmations());
        self::assertSame('pending', $this->connection->fetchOne('SELECT guest_mail_status FROM inquiry_briefs WHERE id = ?', [$brief->id()]));
    }

    private function child(InquiryBrief $brief, MailStatus $status): Inquiry
    {
        return InquiryFactory::new()
            ->afterInstantiate(static function (Inquiry $inquiry) use ($status): void {
                if (MailStatus::Failed === $status) {
                    $inquiry->recordVenueMailFailed(new \DateTimeImmutable());
                    $inquiry->recordGuestMail(MailStatus::Skipped, new \DateTimeImmutable());
                }
            })
            ->create(['brief' => $brief, 'email' => 'gast@example.ch']);
    }

    private function confirmations(): int
    {
        $sent = 0;
        foreach (self::getMailerEvents() as $event) {
            $message = $event->getMessage();
            if (!$event->isQueued() && $message instanceof Email && 'gast@example.ch' === ($message->getTo()[0] ?? null)?->getAddress()) {
                ++$sent;
            }
        }

        return $sent;
    }
}
