<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Bank\BankStatementParserRegistry;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The bank of a ledger: its accounts and their balance, a statement imported,
 * the lines matched to what they pay — by the matchers, from their proposals,
 * or by hand: a document, an account, the other half of a transfer.
 */
#[Route(path: '/accounting/{ledger}/bank/', name: 'accounting_bank_', requirements: ['ledger' => Requirement::UUID])]
final class BankController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    /**
     * How many days apart the two halves of a transfer may be.
     */
    private const int TRANSFER_WINDOW_DAYS = 5;

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        BankAccountRepository $bankAccounts,
        BankTransactionRepository $transactions,
        BankStatementParserRegistry $parsers,
    ): Response {
        $ledger = $this->ledger($ledger);
        $today = new DateTimeImmutable('today');

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'accounts' => array_map(static fn (BankAccount $account) => [
                'account' => $account,
                'balance' => $transactions->sumBetween($account, null, $today->modify('+10 years')),
                'unsettled' => count($transactions->findUnsettled($ledger, $account)),
            ], $bankAccounts->findBy(['ledger' => $ledger], ['label' => 'ASC'])),
            'unsettled' => $transactions->findUnsettled($ledger),
            'parsers' => $parsers->getChoices(),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'account/create', name: 'account_create', methods: [Request::METHOD_POST])]
    public function createAccount(
        string $ledger,
        Request $request,
        ChartService $chart,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::ADMINISTER);

        return $this->act($request, function () use ($ledger, $request, $chart) {
            $label = trim($request->request->getString('label'));

            if ('' === $label) {
                throw new AccountingException('A bank account needs a label.');
            }

            $this->entityManager->persist((new BankAccount())
                ->setLedger($ledger)
                ->setLabel($label)
                ->setIban(preg_replace('/\s+/', '', $request->request->getString('iban')) ?: null)
                ->setAccountNumber($chart->getRoleNumber($ledger, AccountRole::Bank))
                ->setCurrencyCode($ledger->getCurrencyCode()));

            return null;
        }, 'created');
    }

    #[Route(path: 'account/{id}', name: 'account', requirements: ['id' => Requirement::UUID])]
    public function account(
        string $ledger,
        string $id,
        Request $request,
        BankAccountRepository $bankAccounts,
        BankTransactionRepository $transactions,
    ): Response {
        $ledger = $this->ledger($ledger);
        $account = $this->owned($ledger, $bankAccounts->find($id));
        $year = $request->query->getString('year');
        $only = $request->query->getString('only');

        $query = $transactions->createQueryBuilder('t')
            ->andWhere('t.bankAccount = :account')
            ->setParameter('account', $account->getId(), 'uuid')
            ->orderBy('t.date', 'DESC')
            ->setMaxResults(300);

        if (ctype_digit($year)) {
            $query->andWhere('t.date >= :from AND t.date < :to')
                ->setParameter('from', new DateTimeImmutable($year.'-01-01'), 'date_immutable')
                ->setParameter('to', new DateTimeImmutable(((int) $year + 1).'-01-01'), 'date_immutable');
        }

        $lines = $query->getQuery()->getResult();

        if ('unsettled' === $only) {
            $lines = array_values(array_filter($lines, static fn (BankTransaction $line) => ! $line->isSettled() && ! $line->isTransfer()));
        }

        return $this->renderPage('account', [
            'ledger' => $ledger,
            'account' => $account,
            'lines' => $lines,
            'years' => range((int) date('Y'), (int) date('Y') - 4),
        ]);
    }

    /**
     * A statement — CSV, OFX, CAMT, the formats of the ledger's country — read
     * into the account; lines already there are recognised and left out.
     */
    #[Route(path: 'import', name: 'import', methods: [Request::METHOD_POST])]
    public function import(
        string $ledger,
        Request $request,
        BankAccountRepository $bankAccounts,
        BankImportService $importService,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $bankAccounts, $importService) {
            $account = $this->owned($ledger, $bankAccounts->find($request->request->getString('account')));
            $file = $request->files->get('file');

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new AccountingException('Choose the statement file to import.');
            }

            $result = $importService->importContent(
                $account,
                (string) file_get_contents($file->getPathname()),
                $request->request->getString('parser') ?: null,
                filename: $file->getClientOriginalName(),
            );

            $this->notify('info', sprintf('%d line(s) imported, %d already there.', count($result->created), $result->duplicates));

            return $this->generateUrl('accounting_bank_account', ['ledger' => (string) $ledger->getId(), 'id' => (string) $account->getId()]);
        }, 'imported');
    }

    #[Route(path: 'match', name: 'match', methods: [Request::METHOD_POST])]
    public function match(
        string $ledger,
        Request $request,
        MatchingService $matching,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $matching) {
            $this->notify('info', sprintf('%d line(s) matched.', $matching->run($ledger)));

            return null;
        }, 'matched');
    }

    /**
     * One line: what explains it so far, what the matchers propose, and the
     * ways to explain the rest by hand.
     */
    #[Route(path: 'line/{id}', name: 'line', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_GET])]
    public function line(
        string $ledger,
        string $id,
        BankTransactionRepository $transactions,
        InvoiceRepository $invoices,
        MatchingService $matching,
    ): Response {
        $ledger = $this->ledger($ledger);
        $line = $this->ownedLine($ledger, $transactions->find($id));
        $sign = $line->getAmount() >= 0 ? 1 : -1;

        return $this->renderPage('line', [
            'ledger' => $ledger,
            'line' => $line,
            'proposals' => $line->isSettled() || $line->isTransfer() ? [] : $matching->propose($line),
            // What money coming in pays: a sale, or a purchase credited; the
            // reverse for money going out.
            'invoices' => array_values(array_filter(
                $invoices->findAwaitingPayment($ledger),
                static fn (Invoice $invoice) => $invoice->getPaymentSign() * ($invoice->isCreditNote() ? -1 : 1) === $sign
            )),
            'transfer_candidates' => $line->isTransfer() ? [] : $transactions->findTransferCandidates($line, self::TRANSFER_WINDOW_DAYS),
            'peer' => $line->getTransferPeer() ?? $transactions->findTransferSource($line),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'line/{id}/apply', name: 'apply', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function apply(
        string $ledger,
        string $id,
        Request $request,
        BankTransactionRepository $transactions,
        MatchingService $matching,
    ): Response {
        $line = $this->ownedLine($this->ledger($ledger, LedgerPermission::EDIT), $transactions->find($id));

        return $this->act($request, function () use ($line, $request, $matching) {
            $proposal = $matching->propose($line)[$request->request->getInt('proposal')] ?? null;

            if (! $proposal) {
                throw new AccountingException('This proposal is no longer offered.');
            }

            $matching->apply($line, $proposal);

            return null;
        }, 'allocated');
    }

    #[Route(path: 'line/{id}/allocate', name: 'allocate', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function allocate(
        string $ledger,
        string $id,
        Request $request,
        BankTransactionRepository $transactions,
        InvoiceRepository $invoices,
        AllocationService $allocations,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        $line = $this->ownedLine($ledger, $transactions->find($id));

        return $this->act($request, function () use ($ledger, $line, $request, $invoices, $allocations) {
            $amount = $this->minor($request->request->getString('amount'));

            if ('' !== $request->request->getString('invoice')) {
                $invoice = $invoices->find($request->request->getString('invoice'));

                if (! $invoice || $invoice->getLedger() !== $ledger) {
                    throw new AccountingException('This document is not one of these books.');
                }

                // A document in another currency is settled in its own: what
                // this payment pays of it, when the line does not pay it all.
                $allocations->allocate($line, $invoice, $amount, invoiceAmount: $this->minor($request->request->getString('invoiceAmount')));
            } else {
                $number = trim($request->request->getString('accountNumber'));

                if ('' === $number) {
                    throw new AccountingException('Choose a document, or give an account.');
                }

                $allocations->allocateToAccount(
                    $line,
                    $number,
                    $amount,
                    $request->request->getInt('vatRate'),
                    label: $request->request->getString('label') ?: null,
                );
            }

            return null;
        }, 'allocated');
    }

    #[Route(path: 'allocation/{id}/remove', name: 'unallocate', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function unallocate(
        string $ledger,
        string $id,
        Request $request,
        AllocationService $allocations,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        $allocation = $this->entityManager->find(Allocation::class, $id);
        $this->ownedLine($ledger, $allocation?->getTransaction());

        return $this->act($request, function () use ($allocation, $allocations) {
            $allocations->exclude($allocation);

            return null;
        }, 'unallocated');
    }

    #[Route(path: 'line/{id}/transfer', name: 'link_transfer', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function linkTransfer(
        string $ledger,
        string $id,
        Request $request,
        BankTransactionRepository $transactions,
        AllocationService $allocations,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        $line = $this->ownedLine($ledger, $transactions->find($id));
        $peer = $this->ownedLine($ledger, $transactions->find($request->request->getString('peer')));

        return $this->act($request, function () use ($line, $peer, $allocations) {
            $allocations->linkTransfer($line, $peer);

            return null;
        }, 'linked');
    }

    #[Route(path: 'line/{id}/untransfer', name: 'unlink_transfer', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function unlinkTransfer(
        string $ledger,
        string $id,
        Request $request,
        BankTransactionRepository $transactions,
        AllocationService $allocations,
    ): Response {
        $line = $this->ownedLine($this->ledger($ledger, LedgerPermission::EDIT), $transactions->find($id));

        return $this->act($request, function () use ($line, $allocations) {
            $allocations->unlinkTransfer($line);

            return null;
        }, 'unlinked');
    }

    private function owned(
        Ledger $ledger,
        ?BankAccount $account
    ): BankAccount {
        if (! $account || $account->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        return $account;
    }

    private function ownedLine(
        Ledger $ledger,
        ?BankTransaction $line
    ): BankTransaction {
        if (! $line || $line->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        return $line;
    }

    /**
     * An amount as typed — `1 234,50` —, in minor units; nothing typed, null.
     */
    private function minor(string $typed): ?int
    {
        $typed = trim(str_replace(["\u{a0}", "\u{202f}", ' '], '', $typed));

        return '' === $typed ? null : (int) round((float) str_replace(',', '.', $typed) * 100);
    }
}
