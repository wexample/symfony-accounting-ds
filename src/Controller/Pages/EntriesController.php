<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Repository\JournalRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\LetteringService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The entries of a ledger, by journal and fiscal year: one read with its
 * lines, one written by hand, one reversed. The lettering of an account —
 * which of its lines settle each other — is done here too.
 */
#[Route(path: '/accounting/{ledger}/entries/', name: 'accounting_entries_', requirements: ['ledger' => Requirement::UUID])]
final class EntriesController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    /**
     * How many lines the form of an entry written by hand offers.
     */
    private const int HAND_LINES = 8;

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        Request $request,
        JournalEntryRepository $entries,
        JournalRepository $journals,
        FiscalYearRepository $fiscalYears,
    ): Response {
        $ledger = $this->ledger($ledger);
        $years = $fiscalYears->findByLedger($ledger);
        $fiscalYear = $this->pickFiscalYear($years, $request->query->getString('fiscalYear'));
        $journal = $request->query->getString('journal');

        $query = $entries->createQueryBuilder('entry')
            ->join('entry.journal', 'journal')
            ->andWhere('journal.ledger = :ledger')
            ->setParameter('ledger', $ledger->getId(), 'uuid')
            ->orderBy('entry.date', 'DESC')
            ->addOrderBy('entry.number', 'DESC')
            ->setMaxResults(300);

        if ($fiscalYear) {
            $query->andWhere('entry.fiscalYear = :fiscalYear')->setParameter('fiscalYear', $fiscalYear->getId(), 'uuid');
        }

        if ('' !== $journal) {
            $query->andWhere('journal.code = :journal')->setParameter('journal', $journal);
        }

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'entries' => $query->getQuery()->getResult(),
            'journals' => $journals->findBy(['ledger' => $ledger], ['code' => 'ASC']),
            'fiscal_years' => array_reverse($years),
            'fiscal_year' => $fiscalYear,
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'new', name: 'new', methods: [Request::METHOD_GET])]
    public function new(
        string $ledger,
        JournalRepository $journals,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->renderPage('new', [
            'ledger' => $ledger,
            'journals' => $journals->findBy(['ledger' => $ledger], ['code' => 'ASC']),
            'lines' => range(1, self::HAND_LINES),
        ]);
    }

    /**
     * An entry written by hand: the lines filled in, debit or credit, which
     * must balance — the books say so if they do not.
     */
    #[Route(path: 'create', name: 'create', methods: [Request::METHOD_POST])]
    public function create(
        string $ledger,
        Request $request,
        PostingService $posting,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $posting): string {
            $draft = (new EntryDraft(
                $request->request->getString('journal'),
                new DateTimeImmutable($request->request->getString('date') ?: 'today'),
                trim($request->request->getString('label')) ?: throw new AccountingException('An entry needs a label.'),
            ))->piece($request->request->getString('piece') ?: null);

            foreach ($request->request->all('lines') as $line) {
                $account = trim((string) ($line['account'] ?? ''));
                $amount = $this->minor((string) ($line['debit'] ?? '')) - $this->minor((string) ($line['credit'] ?? ''));

                if ('' !== $account && 0 !== $amount) {
                    $draft->add($account, $amount, trim((string) ($line['label'] ?? '')) ?: null);
                }
            }

            $entry = $posting->postDraft($ledger, $draft);

            return $this->entryUrl($ledger, $entry);
        }, 'posted');
    }

    #[Route(path: '{id}', name: 'show', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_GET])]
    public function show(
        string $ledger,
        string $id,
        JournalEntryRepository $entries,
    ): Response {
        $ledger = $this->ledger($ledger);

        return $this->renderPage('show', [
            'ledger' => $ledger,
            'entry' => $this->owned($ledger, $entries->find($id)),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: '{id}/reverse', name: 'reverse', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function reverse(
        string $ledger,
        string $id,
        Request $request,
        JournalEntryRepository $entries,
        PostingService $posting,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        $entry = $this->owned($ledger, $entries->find($id));

        return $this->act($request, function () use ($ledger, $entry, $posting): string {
            return $this->entryUrl($ledger, $posting->reverse($entry));
        }, 'reversed');
    }

    /**
     * The lines of a lettered account — customers, suppliers —: those not
     * lettered yet, to be lettered together when they balance; the letters
     * already given, to be taken back.
     */
    #[Route(path: 'lettering', name: 'lettering', methods: [Request::METHOD_GET])]
    public function lettering(
        string $ledger,
        Request $request,
        AccountRepository $accounts,
        EntryLineRepository $lines,
    ): Response {
        $ledger = $this->ledger($ledger);
        $lettrable = array_values(array_filter($accounts->findByLedger($ledger), static fn ($account) => $account->isLettrable()));
        $account = $accounts->findOneByNumber($ledger, $request->query->getString('account')) ?? ($lettrable[0] ?? null);
        $accountLines = $account ? $lines->findForAccount($ledger, $account) : [];
        $letters = [];

        foreach ($accountLines as $line) {
            if ($line->getLetter()) {
                $letters[$line->getLetter()][] = $line;
            }
        }

        return $this->renderPage('lettering', [
            'ledger' => $ledger,
            'accounts' => $lettrable,
            'account' => $account,
            'open' => array_values(array_filter($accountLines, static fn (EntryLine $line) => null === $line->getLetter())),
            'letters' => $letters,
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'letter', name: 'letter', methods: [Request::METHOD_POST])]
    public function letter(
        string $ledger,
        Request $request,
        LetteringService $lettering,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $lettering) {
            $lines = array_map(
                fn (string $id) => $this->ownedLine($ledger, $this->entityManager->find(EntryLine::class, $id)),
                $request->request->all('lines')
            );

            if (count($lines) < 2) {
                throw new AccountingException('Tick the lines that settle each other.');
            }

            $lettering->letterLines($lines);

            return null;
        }, 'lettered');
    }

    /**
     * Every lettered account of the ledger, its lines lettered wherever they
     * settle each other on their own.
     */
    #[Route(path: 'letter-all', name: 'letter_all', methods: [Request::METHOD_POST])]
    public function letterAll(
        string $ledger,
        Request $request,
        LetteringService $lettering,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $lettering) {
            $this->notify('info', sprintf('%d group(s) lettered.', $lettering->letterLedger($ledger)));

            return null;
        }, 'lettered');
    }

    #[Route(path: 'unletter', name: 'unletter', methods: [Request::METHOD_POST])]
    public function unletter(
        string $ledger,
        Request $request,
        AccountRepository $accounts,
        LetteringService $lettering,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        $account = $accounts->findOneByNumber($ledger, $request->request->getString('account')) ?? throw $this->createNotFoundException();

        return $this->act($request, function () use ($account, $request, $lettering) {
            $lettering->unletter($account, $request->request->getString('letter'));

            return null;
        }, 'unlettered');
    }

    private function pickFiscalYear(
        array $years,
        string $id
    ): ?object {
        foreach ($years as $year) {
            if ((string) $year->getId() === $id) {
                return $year;
            }
        }

        foreach (array_reverse($years) as $year) {
            if ($year->contains(new DateTimeImmutable())) {
                return $year;
            }
        }

        return $years ? end($years) : null;
    }

    private function owned(
        Ledger $ledger,
        ?JournalEntry $entry
    ): JournalEntry {
        if (! $entry || $entry->getJournal()->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        return $entry;
    }

    private function ownedLine(
        Ledger $ledger,
        ?EntryLine $line
    ): EntryLine {
        if (! $line || $line->getEntry()->getJournal()->getLedger() !== $ledger) {
            throw new AccountingException('A line ticked is not one of these books.');
        }

        return $line;
    }

    private function entryUrl(
        Ledger $ledger,
        JournalEntry $entry
    ): string {
        return $this->generateUrl('accounting_entries_show', ['ledger' => (string) $ledger->getId(), 'id' => (string) $entry->getId()]);
    }

    private function minor(string $typed): int
    {
        $typed = trim(str_replace(["\u{a0}", "\u{202f}", ' '], '', $typed));

        return '' === $typed ? 0 : (int) round((float) str_replace(',', '.', $typed) * 100);
    }
}
