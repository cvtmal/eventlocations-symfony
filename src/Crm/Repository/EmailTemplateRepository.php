<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\EmailTemplate;
use App\Crm\Exception\EmailTemplateNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EmailTemplate>
 */
class EmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTemplate::class);
    }

    /**
     * @throws EmailTemplateNotFound
     */
    public function get(string $id): EmailTemplate
    {
        $template = Uuid::isValid($id) ? $this->find($id) : null;

        return $template ?? throw EmailTemplateNotFound::forId($id);
    }

    /**
     * Whether another template holds this name, for the form error. uk_crm_email_templates_name stays the guarantee
     * under concurrency.
     */
    public function nameTaken(string $name, ?string $exceptId = null): bool
    {
        $template = $this->findOneBy(['name' => $name]);

        return null !== $template && $template->id() !== $exceptId;
    }
}
