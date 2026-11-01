<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * Everything the CRM holds on one person (CRM-11, D17), as the JSON an access request is answered
 * with. Staff names of timeline entries and tasks stay out: they are not the person's data.
 */
final readonly class ContactExport
{
    /**
     * @param array{first_name: ?string, last_name: string, email: ?string, phone: ?string, position: ?string, kind: string, owner: ?string, created_at: string} $properties
     * @param list<array{type: string, occurred_at: string, title: ?string, body: ?string, payload: mixed, anonymised_at: ?string}>                              $activities
     * @param list<array{title: string, due_on: string, done_at: ?string}>                                                                                       $tasks
     * @param list<array{name: string, company: string, stage: string, contract_start_on: ?string, contract_end_on: ?string, created_at: string}>                $deals
     */
    public function __construct(
        public string $id,
        public array $properties,
        public ?string $companyName,
        public array $activities,
        public array $tasks,
        public array $deals,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contact' => ['id' => $this->id] + $this->properties,
            'company' => $this->companyName,
            'activities' => $this->activities,
            'tasks' => $this->tasks,
            'deals' => $this->deals,
        ];
    }
}
