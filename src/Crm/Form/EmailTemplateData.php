<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\SaveEmailTemplate;
use App\Crm\ReadModel\EmailTemplateRow;
use App\Crm\ValueObject\PropertyValue;

/**
 * A mail template on the settings screen (CRM-10). The rules are `PropertyConstraints`', set by `EmailTemplateType`.
 */
final class EmailTemplateData
{
    public ?string $name = null;
    public ?string $subject = null;
    public ?string $bodyMarkdown = null;

    public static function fromRow(EmailTemplateRow $row): self
    {
        $data = new self();
        $data->name = $row->name;
        $data->subject = $row->subject;
        $data->bodyMarkdown = $row->bodyMarkdown;

        return $data;
    }

    /**
     * Whether saving would change nothing (P1). Compared canonically, as the handler stores it: a browser posts a
     * textarea with CRLF, the row holds LF.
     */
    public function matches(EmailTemplateRow $row): bool
    {
        return PropertyValue::canonical($this->name) === $row->name
            && PropertyValue::canonical($this->subject) === $row->subject
            && PropertyValue::canonical($this->bodyMarkdown) === $row->bodyMarkdown;
    }

    public function toCommand(string $id): SaveEmailTemplate
    {
        return new SaveEmailTemplate($id, (string) $this->name, (string) $this->subject, (string) $this->bodyMarkdown);
    }
}
