<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\SaveEmailTemplate;
use App\Crm\Entity\EmailTemplate;
use App\Crm\Exception\EmailTemplateNameTaken;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Repository\EmailTemplateRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores the canonical text, so a browser's CRLF stays out. A race on the name ends at
 * uk_crm_email_templates_name, a 500 accepted at this scale (D10).
 */
final class SaveEmailTemplateHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SaveEmailTemplate $command): void
    {
        $name = PropertyValue::canonical($command->name) ?? throw InvalidPropertyValue::required('name');
        $subject = PropertyValue::canonical($command->subject) ?? throw InvalidPropertyValue::required('subject');
        $body = PropertyValue::canonical($command->bodyMarkdown) ?? throw InvalidPropertyValue::required('bodyMarkdown');

        if ($this->templates->nameTaken($name, $command->id)) {
            throw EmailTemplateNameTaken::create();
        }

        $template = $this->templates->find($command->id);
        if (null === $template) {
            $this->em->persist(new EmailTemplate($command->id, $name, $subject, $body));

            return;
        }

        $template->rewrite($name, $subject, $body);
    }
}
