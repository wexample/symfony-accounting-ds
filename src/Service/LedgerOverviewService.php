<?php

namespace Wexample\SymfonyAccountingDs\Service;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;

/**
 * A ledger at a glance: what customers owe it and what it owes suppliers —
 * late or not —, the drafts left, the bank lines not explained yet, the
 * fiscal year it stands in.
 */
class LedgerOverviewService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly BankTransactionRepository $transactionRepository,
        private readonly FiscalYearRepository $fiscalYearRepository,
    ) {
    }

    /**
     * @return array{ledger: Ledger, receivable: int, payable: int, unsettled: int, fiscalYear: ?FiscalYear}
     */
    public function summary(Ledger $ledger): array
    {
        $open = $this->open($ledger);

        return [
            'ledger' => $ledger,
            'receivable' => $open['sale']['amount'],
            'payable' => $open['purchase']['amount'],
            'unsettled' => $this->transactionRepository->countUnsettled($ledger),
            'fiscalYear' => $this->currentFiscalYear($ledger),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Ledger $ledger): array
    {
        return [
            'open' => $this->open($ledger),
            'drafts' => $this->invoiceRepository->count(['ledger' => $ledger, 'status' => InvoiceStatus::Draft]),
            'unsettled' => $this->transactionRepository->countUnsettled($ledger),
            'fiscalYear' => $this->currentFiscalYear($ledger),
            'fiscalYears' => $this->fiscalYearRepository->findByLedger($ledger),
        ];
    }

    /**
     * What is still expected, by direction: how much, in how many documents,
     * and how much of it is past its due date.
     *
     * @return array<string, array{amount: int, count: int, late: int}>
     */
    private function open(Ledger $ledger): array
    {
        $today = new DateTimeImmutable('today');
        $open = ['sale' => ['amount' => 0, 'count' => 0, 'late' => 0], 'purchase' => ['amount' => 0, 'count' => 0, 'late' => 0]];

        foreach ($this->invoiceRepository->findAwaitingPayment($ledger) as $invoice) {
            /** @var Invoice $invoice */
            $side = &$open[$invoice->getDirection()->value];
            $remaining = $invoice->calcRemainingAmount() * ($invoice->isCreditNote() ? -1 : 1);
            $side['amount'] += $remaining;
            ++$side['count'];

            if ($invoice->calcDateDue() < $today) {
                $side['late'] += $remaining;
            }

            unset($side);
        }

        return $open;
    }

    private function currentFiscalYear(Ledger $ledger): ?FiscalYear
    {
        return $this->fiscalYearRepository->findForDate($ledger, new DateTimeImmutable())
            ?? $this->fiscalYearRepository->findLast($ledger);
    }
}
