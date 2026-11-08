<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\ReadModel\DealBoard;
use App\Crm\ReadModel\DealBoardCard;
use App\Crm\ReadModel\DealColumn;
use App\Crm\ReadModel\DealFilter;
use App\Crm\ValueObject\Chf;
use App\Crm\ValueObject\LostReason;
use App\Shared\Twig\SwissFormatExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The props of the `deal-board` island (D12, D16), everything it shows, so the board costs no request after the page.
 * Strings with a value in them carry `%name` markers, which the island fills in.
 */
final readonly class DealBoardProps
{
    /** Island prop => key in `crm`, with the markers it is translated with. */
    private const array LABELS = [
        'sum' => ['deal.board.column_sum', ['sum' => '%sum']],
        'more' => ['deal.board.more', ['n' => '%n']],
        'next' => ['deal.board.next', []],
        'nextTo' => ['deal.board.next_to', ['deal' => '%deal', 'stage' => '%stage']],
        'moveOf' => ['deal.board.move_of', ['deal' => '%deal']],
        'moved' => ['deal.board.moved', ['deal' => '%deal', 'stage' => '%stage']],
        'movedBack' => ['deal.board.moved_back', ['deal' => '%deal', 'stage' => '%stage']],
        'closesOn' => ['deal.board.closes_on', ['date' => '%date']],
        'wonOn' => ['deal.board.won_on', ['date' => '%date']],
        'lostBecause' => ['deal.board.lost_because', ['reason' => '%reason']],
        'owner' => ['deal.board.owner', ['name' => '%name']],
        'lostTitle' => ['deal.board.lost.title', []],
        'lostText' => ['deal.board.lost.text', ['deal' => '%deal']],
        'lostReason' => ['deal.board.lost.reason', []],
        'lostChoose' => ['deal.board.lost.choose', []],
        'lostRequired' => ['deal.board.lost.required', []],
        'lostConfirm' => ['deal.board.lost.confirm', []],
        'lostCancel' => ['deal.board.lost.cancel', []],
        'lostClose' => ['deal.board.lost.close', []],
        'tierTitle' => ['deal.payment.title', []],
        'tierConfirm' => ['deal.payment.confirm', []],
        'tierCancel' => ['deal.payment.cancel', []],
        'tierClose' => ['deal.payment.close', []],
        'stale' => ['deal.board.stale', []],
        'notFound' => ['deal.board.not_found', []],
        'reload' => ['deal.board.reload', []],
        'sessionGone' => ['deal.board.session_gone', []],
        'forbidden' => ['deal.board.forbidden', []],
        'error' => ['deal.board.error', []],
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private SwissFormatExtension $swiss,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(DealBoard $board, DealFilter $filter): array
    {
        return [
            'columns' => array_map(fn (DealColumn $column): array => $this->column($column, $filter), $board->columns),
            'lostReasons' => array_map(fn (LostReason $reason): array => ['value' => $reason->value, 'label' => $reason->trans($this->translator)], LostReason::cases()),
            'labels' => $this->labels(),
        ];
    }

    /**
     * The figure of the head (`Pipeline.dc.html`), the page's text and the island's after a move.
     */
    public function openFigure(int $sumRappen, int $count): string
    {
        return $this->translator->trans('deal.board.open', [
            'sum' => Chf::format($sumRappen),
            'count' => $count,
            'n' => $this->swiss->number($count),
        ], 'crm');
    }

    /**
     * @return array<string, mixed>
     */
    private function column(DealColumn $column, DealFilter $filter): array
    {
        $code = $column->stage->code;

        return [
            'code' => $code,
            'label' => $this->translator->trans('deal.stage.'.$code, [], 'crm'),
            'isWon' => $column->stage->isWon,
            'isLost' => $column->stage->isLost,
            'count' => $column->count,
            'sumRappen' => $column->sumRappen,
            'moreHref' => $this->urls->generate('admin_crm_deals', array_filter(
                ['ansicht' => 'liste', 'stage' => $code, 'owner' => $filter->owner, 'abschluss' => $filter->month],
                static fn (?string $value): bool => null !== $value,
            )),
            'cards' => array_map($this->card(...), $column->cards),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(DealBoardCard $card): array
    {
        return [
            'id' => $card->id,
            'name' => $card->name,
            'company' => $card->companyName,
            'href' => $this->urls->generate('admin_crm_deals_show', ['id' => $card->id]),
            'stageUrl' => $this->urls->generate('admin_api_crm_deal_stage', ['id' => $card->id]),
            'tier' => null === $card->tier ? null : ['code' => $card->tier->code, 'label' => $card->tier->name, 'promoted' => $card->tier->promoted],
            'amountRappen' => $card->amountRappen,
            'expectedCloseOn' => $card->expectedCloseOn,
            'closedOn' => $card->closedOn,
            'lostReason' => $card->lostReason?->value,
            'ownerName' => $card->ownerName,
            'ownerInitials' => $card->ownerInitials(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = ['openOne' => $this->openLabel(1), 'openOther' => $this->openLabel(2)];
        foreach (self::LABELS as $prop => [$key, $markers]) {
            $labels[$prop] = $this->translator->trans($key, $markers, 'crm');
        }

        return $labels;
    }

    private function openLabel(int $count): string
    {
        return $this->translator->trans('deal.board.open', ['sum' => '%sum', 'count' => $count, 'n' => '%n'], 'crm');
    }
}
