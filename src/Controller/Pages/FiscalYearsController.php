<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearClosingService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The fiscal years of a ledger: one checked before it is closed — what the
 * check finds, by how grave —, closed, reopened, and the next one opened.
 */
#[Route(path: '/accounting/{ledger}/fiscal-years/', name: 'accounting_fiscal_years_', requirements: ['ledger' => Requirement::UUID])]
final class FiscalYearsController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        FiscalYearRepository $fiscalYears,
    ): Response {
        $ledger = $this->ledger($ledger);

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'fiscal_years' => array_reverse($fiscalYears->findByLedger($ledger)),
            'can_administer' => $this->isGranted(LedgerPermission::ADMINISTER->value, $ledger),
        ]);
    }

    #[Route(path: '{id}', name: 'check', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_GET])]
    public function check(
        string $ledger,
        string $id,
        FiscalYearRepository $fiscalYears,
        FiscalYearClosingService $closing,
    ): Response {
        $ledger = $this->ledger($ledger);
        $fiscalYear = $this->owned($ledger, $fiscalYears->find($id));
        $report = $closing->check($fiscalYear);
        $findings = [];

        foreach ($report->getSubjects() as $subject) {
            foreach ($report->getFindings($subject) as $finding) {
                $findings[] = [
                    'severity' => $finding->severity->value,
                    'code' => $finding->code,
                    'parameters' => $finding->parameters,
                    'domain' => $finding->domain,
                    'subject' => $this->describe($subject),
                ];
            }
        }

        usort($findings, static fn ($a, $b) => array_search($a['severity'], ['error', 'warning', 'info'], true) <=> array_search($b['severity'], ['error', 'warning', 'info'], true));

        return $this->renderPage('check', [
            'ledger' => $ledger,
            'fiscal_year' => $fiscalYear,
            'findings' => $findings,
            'result' => $closing->computeResult($fiscalYear),
            'can_administer' => $this->isGranted(LedgerPermission::ADMINISTER->value, $ledger),
        ]);
    }

    #[Route(path: '{id}/close', name: 'close', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function close(
        string $ledger,
        string $id,
        Request $request,
        FiscalYearRepository $fiscalYears,
        FiscalYearClosingService $closing,
    ): Response {
        $fiscalYear = $this->owned($this->ledger($ledger, LedgerPermission::ADMINISTER), $fiscalYears->find($id));

        return $this->act($request, function () use ($fiscalYear, $request, $closing) {
            $closing->close($fiscalYear, $request->request->getBoolean('force'));

            return null;
        }, 'closed');
    }

    #[Route(path: '{id}/reopen', name: 'reopen', requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function reopen(
        string $ledger,
        string $id,
        Request $request,
        FiscalYearRepository $fiscalYears,
        FiscalYearClosingService $closing,
    ): Response {
        $fiscalYear = $this->owned($this->ledger($ledger, LedgerPermission::ADMINISTER), $fiscalYears->find($id));

        return $this->act($request, function () use ($fiscalYear, $closing) {
            $closing->reopen($fiscalYear);

            return null;
        }, 'reopened');
    }

    #[Route(path: 'next', name: 'next', methods: [Request::METHOD_POST])]
    public function next(
        string $ledger,
        Request $request,
        FiscalYearService $fiscalYearService,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::ADMINISTER);

        return $this->act($request, function () use ($ledger, $fiscalYearService) {
            $fiscalYearService->createNext($ledger);

            return null;
        }, 'next');
    }

    private function owned(
        Ledger $ledger,
        ?FiscalYear $fiscalYear
    ): FiscalYear {
        if (! $fiscalYear || $fiscalYear->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        return $fiscalYear;
    }

    /**
     * What a finding is about, in a few words: a document's number, an
     * entry's label, a bank line's.
     */
    private function describe(object $subject): string
    {
        foreach (['getNumber', 'getLabel', 'getName'] as $getter) {
            if (method_exists($subject, $getter) && null !== $value = $subject->{$getter}()) {
                return (string) $value;
            }
        }

        return (new \ReflectionClass($subject))->getShortName();
    }
}
