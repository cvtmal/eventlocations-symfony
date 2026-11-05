<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Admin\Entity\AdminUser;
use App\Admin\Extension\TimelineActivityTypeRegistry;
use App\Crm\Command\LogActivity;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityDeal;
use App\Crm\Entity\ActivityType;
use App\Crm\Exception\ActivityReadOnly;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a manual entry (P7: `body` only) and links it. An entry on a deal also goes on its company, so it shows on
 * both timelines; one on a company stays there (P8). The record is locked, so a delete either sees the new link or
 * this handler sees the record gone. A deal locks company first (D11).
 */
final class LogActivityHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TimelineActivityTypeRegistry $types,
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(LogActivity $command): void
    {
        $type = $this->types->isComposable($command->type) ? $this->em->find(ActivityType::class, $command->type) : null;
        if (null === $type) {
            throw ActivityReadOnly::forType($command->type);
        }
        $body = PropertyValue::canonical($command->body) ?? throw InvalidPropertyValue::required('body');

        $activity = new Activity(
            $command->id,
            $type,
            $command->occurredAt,
            $this->em->getReference(AdminUser::class, $command->actingUserId),
            null,
            $body,
        );

        $links = match ($command->kind) {
            RecordKind::Company => [new ActivityCompany($activity, $this->companies->lock($command->recordId))],
            RecordKind::Deal => $this->dealLinks($activity, $command->recordId),
        };

        $this->em->persist($activity);
        foreach ($links as $link) {
            $this->em->persist($link);
        }
    }

    /**
     * @return list<ActivityCompany|ActivityDeal>
     */
    private function dealLinks(Activity $activity, string $dealId): array
    {
        $company = $this->companies->lock($this->deals->companyIdOf($dealId));

        return [new ActivityDeal($activity, $this->deals->lock($dealId)), new ActivityCompany($activity, $company)];
    }
}
