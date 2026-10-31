<?php

declare(strict_types=1);

namespace App\Crm\Twig;

use App\Crm\ValueObject\TaskDue;
use App\Crm\ValueObject\TaskDueDate;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The due pill of a task, one rule for the task list, the Aufgaben card, the venue panel and #27b's widgets:
 * "Überfällig", "Heute", else the date (`Mo, 3.11.`, with the year when it is not this one). A done task only has
 * its date. Today is Zurich's, from the clock.
 */
final readonly class TaskDueExtension
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private ClockInterface $clock,
        private TranslatorInterface $translator,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * @return array{label: string, tone: string}
     */
    #[AsTwigFunction('crm_task_due')]
    public function due(string $dueOn, bool $done = false): array
    {
        $today = TaskDueDate::today($this->clock->now());
        $due = $done ? TaskDue::Later : TaskDue::of($dueOn, $today);

        return [
            'label' => TaskDue::Later === $due ? $this->date($dueOn, $today) : $this->translator->trans('task.due.'.$due->value, [], 'crm'),
            'tone' => $due->tone(),
        ];
    }

    private function date(string $dueOn, string $today): string
    {
        $pattern = substr($dueOn, 0, 4) === substr($today, 0, 4) ? 'EEEEEE, d.M.' : 'EEEEEE, d.M.yyyy';
        $formatter = new \IntlDateFormatter($this->localeSwitcher->getLocale().'_CH', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::ZONE, null, $pattern);
        $formatted = $formatter->format(new \DateTimeImmutable($dueOn, new \DateTimeZone(self::ZONE)));

        return false === $formatted ? $dueOn : $formatted;
    }
}
